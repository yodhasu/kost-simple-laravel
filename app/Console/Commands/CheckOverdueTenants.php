<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckOverdueTenants extends Command
{
    protected $signature = 'tenants:check-overdue';

    protected $description = 'Mark active tenants as telat if their rent is overdue (last payment + 1 month < today)';

    public function handle(): int
    {
        $today = Carbon::today();

        // Get all active tenants that are currently 'aktif'
        $tenants = Tenant::query()
            ->where('is_active', true)
            ->where('status', 'aktif')
            ->get();

        if ($tenants->isEmpty()) {
            $this->info('No active tenants to check.');

            return self::SUCCESS;
        }

        // Get each tenant's latest rent payment date in one query
        $latestPayments = Transaction::query()
            ->select('tenant_id', DB::raw('MAX(transaction_date) as last_payment_date'))
            ->where('financial_class', 'REVENUE')
            ->where('category', 'rent')
            ->where('is_frozen', false)
            ->whereIn('tenant_id', $tenants->pluck('id'))
            ->groupBy('tenant_id')
            ->pluck('last_payment_date', 'tenant_id');

        $overdueCount = 0;

        foreach ($tenants as $tenant) {
            $lastPaymentDate = isset($latestPayments[$tenant->id])
                ? Carbon::parse($latestPayments[$tenant->id])
                : null;

            // If no payment at all, use start_date as baseline
            $baseDate = $lastPaymentDate ?? $tenant->start_date;

            if (! $baseDate) {
                continue; // No start_date and no payment — skip
            }

            $baseDate = Carbon::parse($baseDate);

            // Next due date = base date + 1 month
            // Carbon handles overflow: Jan 31 → Feb 28, but we want
            // edge cases like Feb 29 → Mar 1 (next valid date)
            $nextDueDate = $baseDate->copy()->addMonthNoOverflow();

            // If the day overflowed (e.g., Jan 31 → Feb 28), push to 1st of next month
            if ($baseDate->day > $nextDueDate->daysInMonth) {
                $nextDueDate = $nextDueDate->copy()->startOfMonth()->addMonth();
            }

            if ($today->greaterThan($nextDueDate)) {
                $tenant->status = 'telat';
                $tenant->save();
                $overdueCount++;
            }
        }

        $this->info("Checked {$tenants->count()} tenants. Marked {$overdueCount} as telat.");

        return self::SUCCESS;
    }
}
