<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class TenantBillingService
{
    public const STATUS_DP = 'DP';

    public const STATUS_LUNAS = 'LUNAS';

    public const STATUS_BELUM_LUNAS = 'BELUM LUNAS';

    public const STATUS_JATUH_TEMPO = 'JATUH TEMPO';

    public const STATUS_TELAT_BAYAR = 'TELAT BAYAR';

    public const STATUS_ON_HOLD = 'ON HOLD';

    /**
     * @return array<int, string>
     */
    public static function manualStatuses(): array
    {
        return [
            self::STATUS_LUNAS,
            self::STATUS_ON_HOLD,
            self::STATUS_DP,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function billingStatuses(): array
    {
        return [
            self::STATUS_DP,
            self::STATUS_LUNAS,
            self::STATUS_BELUM_LUNAS,
            self::STATUS_JATUH_TEMPO,
            self::STATUS_TELAT_BAYAR,
            self::STATUS_ON_HOLD,
        ];
    }

    public function settlePayment(Tenant $tenant, int $paymentAmount, CarbonInterface|string $asOf): Tenant
    {
        if ($this->isDp($tenant) || $this->isOnHold($tenant) || ! $tenant->start_date) {
            return $tenant;
        }

        $rentPrice = max(0, (int) ($tenant->rent_price ?? 0));
        $available = max(0, (int) ($tenant->prepaid_balance ?? 0)) + max(0, $paymentAmount);
        $cursor = $this->nextDueDate($tenant);

        if ($cursor === null || $rentPrice <= 0) {
            $tenant->prepaid_balance = $available;
            $tenant->status = self::STATUS_LUNAS;

            return $tenant;
        }

        while ($available >= $rentPrice) {
            $available -= $rentPrice;
            $tenant->paid_until = $cursor->toDateString();
            $cursor = $this->nextDueDate($tenant);
        }

        $tenant->prepaid_balance = $available;

        return $this->refreshStatus($tenant, $asOf);
    }

    public function describeRegularPayment(Tenant $before, Tenant $after, int $paymentAmount, CarbonInterface|string|null $asOf = null): string
    {
        $appliedAmount = max(0, $paymentAmount);
        $fullyCoveredMonths = $this->monthsCoveredByPayment($before, $after);
        $carryover = max(0, (int) ($after->prepaid_balance ?? 0));

        $segments = ['Pembayaran sewa'];

        if ($fullyCoveredMonths !== []) {
            $segments[] = 'bulan '.implode(', ', array_map(
                fn (CarbonImmutable $date) => $this->formatBillingMonth($date),
                $fullyCoveredMonths,
            ));
        }

        if ($fullyCoveredMonths === []) {
            $segments[] = 'alokasi Rp'.number_format($appliedAmount, 0, ',', '.');
        }

        $segments[] = 'carryover balance Rp'.number_format($carryover, 0, ',', '.');

        return implode(' | ', array_filter($segments));
    }

    public function refreshStatus(Tenant $tenant, CarbonInterface|string|null $asOf = null): Tenant
    {
        if ($this->isDp($tenant) || $this->isOnHold($tenant) || ! $tenant->is_active || ! $tenant->start_date) {
            return $tenant;
        }

        $now = $this->normalizeDate($asOf);
        $currentDueDate = $this->currentDueDate($tenant, $now);
        $oldestUncoveredDueDate = $this->nextDueDate($tenant);

        if ($currentDueDate === null || $oldestUncoveredDueDate === null) {
            $tenant->status = self::STATUS_LUNAS;

            return $tenant;
        }

        $paidUntil = $tenant->paid_until ? CarbonImmutable::parse($tenant->paid_until) : null;
        $prepaidBalance = max(0, (int) ($tenant->prepaid_balance ?? 0));

        if ($paidUntil && $paidUntil->greaterThanOrEqualTo($currentDueDate)) {
            $tenant->status = self::STATUS_LUNAS;

            return $tenant;
        }

        if ($oldestUncoveredDueDate->lessThan($currentDueDate)) {
            $tenant->status = $oldestUncoveredDueDate->lessThan($now)
                ? self::STATUS_TELAT_BAYAR
                : ($oldestUncoveredDueDate->isSameDay($now) ? self::STATUS_JATUH_TEMPO : self::STATUS_BELUM_LUNAS);

            return $tenant;
        }

        if ($prepaidBalance > 0) {
            $tenant->status = self::STATUS_BELUM_LUNAS;

            return $tenant;
        }

        if ($now->lessThan($currentDueDate)) {
            $tenant->status = self::STATUS_BELUM_LUNAS;

            return $tenant;
        }

        if ($now->isSameDay($currentDueDate)) {
            $tenant->status = self::STATUS_JATUH_TEMPO;

            return $tenant;
        }

        $tenant->status = self::STATUS_TELAT_BAYAR;

        return $tenant;
    }

    public function refreshTrackedStatus(Tenant $tenant, CarbonInterface|string|null $asOf = null): Tenant
    {
        if ($this->isOnHold($tenant)) {
            return $tenant;
        }

        if ($this->isDp($tenant)) {
            $tenant->status = $this->resolveDpStatus($tenant, $asOf);

            return $tenant;
        }

        return $this->refreshStatus($tenant, $asOf);
    }

    public function repriceFutureCoverage(Tenant $tenant, int $oldRentPrice, CarbonInterface|string|null $asOf = null): Tenant
    {
        if ($this->isDp($tenant) || $this->isOnHold($tenant) || ! $tenant->start_date) {
            return $tenant;
        }

        $now = $this->normalizeDate($asOf);
        $currentDueDate = $this->currentDueDate($tenant, $now);
        $paidUntil = $tenant->paid_until ? CarbonImmutable::parse($tenant->paid_until) : null;
        $prepaidBalance = max(0, (int) ($tenant->prepaid_balance ?? 0));

        if ($oldRentPrice <= 0 || $paidUntil === null || $currentDueDate === null || $paidUntil->lessThanOrEqualTo($currentDueDate)) {
            return $this->refreshStatus($tenant, $now);
        }

        $futureMonthsCovered = $this->countCoveredFutureMonths(CarbonImmutable::parse($tenant->start_date), $currentDueDate, $paidUntil);
        $futureCredit = ($futureMonthsCovered * $oldRentPrice) + $prepaidBalance;

        $tenant->paid_until = $currentDueDate->toDateString();
        $tenant->prepaid_balance = $futureCredit;

        return $this->settlePayment($tenant, 0, $now);
    }

    public function currentDueAmount(Tenant $tenant, CarbonInterface|string|null $asOf = null): int
    {
        if ($this->isDp($tenant)) {
            return $this->dpRemainingAmount($tenant);
        }

        if ($this->isOnHold($tenant) || ! $tenant->start_date) {
            return 0;
        }

        $now = $this->normalizeDate($asOf);
        $currentDueDate = $this->currentDueDate($tenant, $now);
        $paidUntil = $tenant->paid_until ? CarbonImmutable::parse($tenant->paid_until) : null;

        if ($currentDueDate === null || ($paidUntil && $paidUntil->greaterThanOrEqualTo($currentDueDate))) {
            return 0;
        }

        return max(0, (int) ($tenant->rent_price ?? 0) - max(0, (int) ($tenant->prepaid_balance ?? 0)));
    }

    public function totalOutstandingAmount(Tenant $tenant, CarbonInterface|string|null $asOf = null): int
    {
        if ($this->isDp($tenant)) {
            return $this->dpRemainingAmount($tenant);
        }

        if ($this->isOnHold($tenant) || ! $tenant->start_date) {
            return 0;
        }

        $now = $this->normalizeDate($asOf);
        $currentDueDate = $this->currentDueDate($tenant, $now);
        $oldestUncoveredDueDate = $this->nextDueDate($tenant);
        $rentPrice = max(0, (int) ($tenant->rent_price ?? 0));
        $prepaidBalance = max(0, (int) ($tenant->prepaid_balance ?? 0));

        if ($currentDueDate === null || $oldestUncoveredDueDate === null || $rentPrice <= 0) {
            return 0;
        }

        if ($oldestUncoveredDueDate->greaterThan($currentDueDate)) {
            return 0;
        }

        $dueCount = 1;
        $cursor = $oldestUncoveredDueDate;
        $startDate = CarbonImmutable::parse($tenant->start_date);

        while ($cursor->lessThan($currentDueDate)) {
            $cursor = $this->advanceDueDate($startDate, $cursor);
            $dueCount++;
        }

        return max(0, ($dueCount * $rentPrice) - $prepaidBalance);
    }

    public function nextBillingDate(Tenant $tenant): ?CarbonImmutable
    {
        if ($this->isDp($tenant)) {
            $dpDueDate = $this->dpDueDate($tenant);

            return $dpDueDate ? CarbonImmutable::parse($dpDueDate)->startOfDay() : null;
        }

        return $this->nextDueDate($tenant);
    }

    public function nextDueDate(Tenant $tenant): ?CarbonImmutable
    {
        if (! $tenant->start_date) {
            return null;
        }

        $paidUntil = $tenant->paid_until ? CarbonImmutable::parse($tenant->paid_until) : null;

        if ($paidUntil === null) {
            return CarbonImmutable::parse($tenant->start_date);
        }

        return $this->advanceDueDate(CarbonImmutable::parse($tenant->start_date), $paidUntil);
    }

    public function currentDueDate(Tenant $tenant, CarbonInterface|string|null $asOf = null): ?CarbonImmutable
    {
        if (! $tenant->start_date) {
            return null;
        }

        $now = $this->normalizeDate($asOf);
        $startDate = CarbonImmutable::parse($tenant->start_date);

        return $this->dueDateForMonth($startDate, $now->year, $now->month);
    }

    public function isDp(Tenant $tenant): bool
    {
        if (($tenant->getAttribute('is_dp') ?? null) !== null) {
            return (bool) $tenant->getAttribute('is_dp');
        }

        if (strtoupper((string) $tenant->status) === self::STATUS_DP) {
            return true;
        }

        if (! $tenant->id) {
            return false;
        }

        return Transaction::query()
            ->where('tenant_id', $tenant->id)
            ->where('category', 'dp')
            ->where('is_frozen', true)
            ->exists();
    }

    public function isOnHold(Tenant $tenant): bool
    {
        return strtoupper((string) $tenant->status) === self::STATUS_ON_HOLD;
    }

    public function dpDueDate(Tenant $tenant): ?string
    {
        $attributeDueDate = $tenant->getAttribute('dp_due_date');

        if (is_string($attributeDueDate) && $attributeDueDate !== '') {
            return $attributeDueDate;
        }

        if (! $tenant->id) {
            return null;
        }

        $dpTransaction = Transaction::query()
            ->where('tenant_id', $tenant->id)
            ->where('category', 'dp')
            ->where('is_frozen', true)
            ->latest('transaction_date')
            ->latest('created_at')
            ->first();

        return $this->extractDueDateFromDescription($dpTransaction?->description);
    }

    public function dpBaseAmount(Tenant $tenant): int
    {
        $attributeAmount = $tenant->getAttribute('dp_amount');

        if (is_numeric($attributeAmount)) {
            return max(0, (int) $attributeAmount);
        }

        if (! $tenant->id) {
            return 0;
        }

        $dpTransaction = Transaction::query()
            ->where('tenant_id', $tenant->id)
            ->where('category', 'dp')
            ->latest('transaction_date')
            ->latest('created_at')
            ->first();

        return max(0, (int) ($dpTransaction?->amount ?? 0));
    }

    public function dpPaidTotal(Tenant $tenant): int
    {
        if (! $tenant->id) {
            return $this->dpBaseAmount($tenant);
        }

        $dpTransaction = Transaction::query()
            ->where('tenant_id', $tenant->id)
            ->where('category', 'dp')
            ->latest('transaction_date')
            ->latest('created_at')
            ->first();

        $baseAmount = max(0, (int) ($dpTransaction?->amount ?? $this->dpBaseAmount($tenant)));

        if (! $dpTransaction?->id) {
            return $baseAmount;
        }

        $additionalPaid = (int) Transaction::query()
            ->where('tenant_id', $tenant->id)
            ->where('category', 'rent')
            ->where('reference_id', $dpTransaction->id)
            ->sum('amount');

        return $baseAmount + max(0, $additionalPaid);
    }

    public function dpRemainingAmount(Tenant $tenant): int
    {
        return max(0, (int) ($tenant->rent_price ?? 0) - $this->dpPaidTotal($tenant));
    }

    public function resolveDpStatus(Tenant $tenant, CarbonInterface|string|null $asOf = null): string
    {
        if ($this->dpRemainingAmount($tenant) <= 0) {
            return self::STATUS_LUNAS;
        }

        $dpDueDate = $this->dpDueDate($tenant);

        if (! $dpDueDate) {
            return self::STATUS_DP;
        }

        if (CarbonImmutable::parse($dpDueDate)->startOfDay()->lt($this->normalizeDate($asOf))) {
            return self::STATUS_JATUH_TEMPO;
        }

        return self::STATUS_DP;
    }

    private function countCoveredFutureMonths(CarbonImmutable $startDate, CarbonImmutable $currentDueDate, CarbonImmutable $paidUntil): int
    {
        $months = 0;
        $cursor = $currentDueDate;

        while ($cursor->lessThan($paidUntil)) {
            $cursor = $this->advanceDueDate($startDate, $cursor);
            $months++;
        }

        return $months;
    }

    private function dueDateForMonth(CarbonImmutable $startDate, int $year, int $month): CarbonImmutable
    {
        $monthStart = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $startDate->timezone);
        $daysInMonth = $monthStart->daysInMonth;
        $day = min($startDate->day, $daysInMonth);
        $candidate = $monthStart->day($day);

        if ($startDate->day > $daysInMonth) {
            $candidate = $candidate->addDay();
        }

        return $candidate->startOfDay();
    }

    private function advanceDueDate(CarbonImmutable $startDate, CarbonImmutable $currentDueDate): CarbonImmutable
    {
        $nextMonth = $currentDueDate->addMonthNoOverflow();
        $target = $this->dueDateForMonth($startDate, $nextMonth->year, $nextMonth->month);

        if ($target->lessThanOrEqualTo($currentDueDate)) {
            $afterTarget = $nextMonth->addMonthNoOverflow();
            $target = $this->dueDateForMonth($startDate, $afterTarget->year, $afterTarget->month);
        }

        return $target;
    }

    private function normalizeDate(CarbonInterface|string|null $value = null): CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->startOfDay();
        }

        return CarbonImmutable::now()->startOfDay();
    }

    private function extractDueDateFromDescription(?string $description): ?string
    {
        if (! $description || ! preg_match('/due_date:(\d{4}-\d{2}-\d{2})/', $description, $matches)) {
            return null;
        }

        return $matches[1] ?? null;
    }

    /**
     * @return array<int, CarbonImmutable>
     */
    private function monthsCoveredByPayment(Tenant $before, Tenant $after): array
    {
        if (! $before->start_date || ! $after->start_date) {
            return [];
        }

        $beforeNextDue = $this->nextDueDate($before);
        $afterPaidUntil = $after->paid_until ? CarbonImmutable::parse($after->paid_until) : null;

        if ($beforeNextDue === null || $afterPaidUntil === null || $afterPaidUntil->lt($beforeNextDue)) {
            return [];
        }

        $startDate = CarbonImmutable::parse($before->start_date);
        $covered = [];
        $cursor = $beforeNextDue;

        while ($cursor->lessThanOrEqualTo($afterPaidUntil)) {
            $covered[] = $cursor;
            $cursor = $this->advanceDueDate($startDate, $cursor);
        }

        return $covered;
    }

    private function formatBillingMonth(CarbonImmutable $date): string
    {
        return match ($date->month) {
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            default => 'Desember',
        }.' '.$date->year;
    }
}
