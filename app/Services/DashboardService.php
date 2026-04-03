<?php

namespace App\Services;

use App\Models\Kost;
use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function getSummary(?string $kostId = null, ?string $regionId = null): array
    {
        return [
            'stats' => $this->getStats($kostId, $regionId),
            'trend_bars' => $this->getTrendBars($kostId, $regionId),
            'finance_overview' => $this->getFinanceOverview($kostId, $regionId),
            'dp_total' => (int) Transaction::query()
                ->when($kostId, fn ($query) => $query->where('kost_id', $kostId))
                ->when($regionId && ! $kostId, fn ($query) => $query->where('region_id', $regionId))
                ->where('category', 'dp')
                ->where('is_frozen', true)
                ->where('financial_class', 'LIABILITY')
                ->sum('amount'),
            'dp_count' => Transaction::query()
                ->when($kostId, fn ($query) => $query->where('kost_id', $kostId))
                ->when($regionId && ! $kostId, fn ($query) => $query->where('region_id', $regionId))
                ->where('category', 'dp')
                ->where('is_frozen', true)
                ->where('financial_class', 'LIABILITY')
                ->distinct('tenant_id')
                ->count('tenant_id'),
        ];
    }

    public function getStats(?string $kostId = null, ?string $regionId = null): array
    {
        $totalRooms = (int) Kost::query()
            ->when($kostId, fn ($query) => $query->where('id', $kostId))
            ->when($regionId && ! $kostId, fn ($query) => $query->where('region_id', $regionId))
            ->sum('total_units');

        $activeTenants = Tenant::query()
            ->where('status', 'aktif')
            ->where('is_active', true)
            ->when($kostId, fn ($query) => $query->where('kost_id', $kostId))
            ->when($regionId && ! $kostId, function ($query) use ($regionId): void {
                $query->whereHas('kost', fn ($kostQuery) => $kostQuery->where('region_id', $regionId));
            })
            ->count();

        $lastMonthStart = now()->startOfMonth()->subMonth();
        $lastMonthCount = Tenant::query()
            ->where('status', 'aktif')
            ->where('is_active', true)
            ->where('created_at', '<', $lastMonthStart)
            ->when($kostId, fn ($query) => $query->where('kost_id', $kostId))
            ->when($regionId && ! $kostId, function ($query) use ($regionId): void {
                $query->whereHas('kost', fn ($kostQuery) => $kostQuery->where('region_id', $regionId));
            })
            ->count();

        $tenantChange = $lastMonthCount > 0
            ? round((($activeTenants - $lastMonthCount) / $lastMonthCount) * 100, 1)
            : null;

        $revenue = (int) Transaction::query()
            ->where('financial_class', 'REVENUE')
            ->where('is_frozen', false)
            ->whereDate('transaction_date', '<=', now())
            ->when($kostId, fn ($query) => $query->where('kost_id', $kostId))
            ->when($regionId && ! $kostId, fn ($query) => $query->where('region_id', $regionId))
            ->sum('amount');

        $expense = (int) Transaction::query()
            ->where('financial_class', 'EXPENSE')
            ->whereDate('transaction_date', '<=', now())
            ->when($kostId, fn ($query) => $query->where('kost_id', $kostId))
            ->when($regionId && ! $kostId, fn ($query) => $query->where('region_id', $regionId))
            ->sum('amount');

        $overdueQuery = Tenant::query()
            ->where('status', 'telat')
            ->where('is_active', true)
            ->when($kostId, fn ($query) => $query->where('kost_id', $kostId))
            ->when($regionId && ! $kostId, function ($query) use ($regionId): void {
                $query->whereHas('kost', fn ($kostQuery) => $kostQuery->where('region_id', $regionId));
            });

        $overdueByKost = (clone $overdueQuery)
            ->join('kosts', 'tenants.kost_id', '=', 'kosts.id')
            ->select('kosts.name as kost_name', DB::raw('COUNT(*) as count'))
            ->groupBy('kosts.name')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['kost_name' => $row->kost_name, 'count' => (int) $row->count])
            ->all();

        $overdueTenants = $overdueQuery->count();

        $emptyRooms = max(0, $totalRooms - $activeTenants);

        return [
            'total_tenants' => $activeTenants,
            'total_rooms' => $totalRooms,
            'empty_rooms' => $emptyRooms,
            'occupancy_rate' => $totalRooms > 0 ? round(($activeTenants / $totalRooms) * 100, 1) : 0,
            'tenant_change_percent' => $tenantChange,
            'net_revenue_to_date' => $revenue - $expense,
            'overdue_tenants' => $overdueTenants,
            'overdue_by_kost' => $overdueByKost,
        ];
    }

    public function getTrendBars(?string $kostId = null, ?string $regionId = null): array
    {
        $today = now();
        $start = $today->copy()->startOfMonth();
        $end = $today->copy()->endOfMonth();
        $buckets = [
            [1, 7],
            [8, 14],
            [15, 21],
            [22, $end->day],
        ];

        $items = collect($buckets)->map(function (array $bucket) use ($today, $kostId, $regionId) {
            [$startDay, $endDay] = $bucket;
            $bucketStart = $today->copy()->day($startDay)->toDateString();
            $bucketEnd = $today->copy()->day($endDay)->toDateString();

            $income = (int) $this->baseTransactionQuery($kostId, $regionId)
                ->where('financial_class', 'REVENUE')
                ->where('is_frozen', false)
                ->whereBetween('transaction_date', [$bucketStart, $bucketEnd])
                ->sum('amount');

            $fee = (int) $this->baseTransactionQuery($kostId, $regionId)
                ->where('financial_class', 'EXPENSE')
                ->whereNotNull('reference_id')
                ->whereBetween('transaction_date', [$bucketStart, $bucketEnd])
                ->sum('amount');

            $expense = (int) $this->baseTransactionQuery($kostId, $regionId)
                ->where('financial_class', 'EXPENSE')
                ->whereBetween('transaction_date', [$bucketStart, $bucketEnd])
                ->sum('amount');

            return [
                'label' => "{$startDay}-{$endDay}",
                'income' => $income,
                'expense' => $expense,
            ];
        });

        return [
            'period' => 'Bulan '.$this->monthAbbreviation($today).' '.$today->year,
            'items' => $items->all(),
        ];
    }

    public function getFinanceOverview(?string $kostId = null, ?string $regionId = null): array
    {
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        $monthlyTransactions = $this->baseTransactionQuery($kostId, $regionId)
            ->with('kost:id,name')
            ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->get();

        $incomeTransactions = $monthlyTransactions
            ->where('financial_class', 'REVENUE')
            ->where('is_frozen', false);

        $expenseTransactions = $monthlyTransactions
            ->where('financial_class', 'EXPENSE');

        $incomeByKost = $incomeTransactions
            ->groupBy(fn (Transaction $transaction) => $transaction->kost?->name ?: 'Tanpa Kost')
            ->map(fn ($group, string $name) => [
                'label' => $name,
                'value' => (int) $group->sum('amount'),
            ])
            ->sortByDesc('value')
            ->values()
            ->all();

        $expenseByCategory = $expenseTransactions
            ->groupBy(fn (Transaction $transaction) => $this->formatCategoryLabel($transaction->category))
            ->map(fn ($group, string $name) => [
                'label' => $name,
                'value' => (int) $group->sum('amount'),
            ])
            ->sortByDesc('value')
            ->values()
            ->all();

        return [
            'period' => now()->translatedFormat('M Y'),
            'income_total' => (int) $incomeTransactions->sum('amount'),
            'expense_total' => (int) $expenseTransactions->sum('amount'),
            'income_by_kost' => $incomeByKost,
            'expense_by_category' => $expenseByCategory,
        ];
    }

    private function baseTransactionQuery(?string $kostId, ?string $regionId)
    {
        return Transaction::query()
            ->when($kostId, fn ($query) => $query->where('kost_id', $kostId))
            ->when($regionId && ! $kostId, fn ($query) => $query->where('region_id', $regionId));
    }

    private function formatCategoryLabel(?string $category): string
    {
        if (! $category) {
            return 'Lainnya';
        }

        return str($category)
            ->replace('_', ' ')
            ->headline()
            ->toString();
    }

    private function monthAbbreviation(CarbonInterface $date): string
    {
        return [
            1 => 'Jan',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Apr',
            5 => 'Mei',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Agu',
            9 => 'Sep',
            10 => 'Okt',
            11 => 'Nov',
            12 => 'Des',
        ][$date->month];
    }
}
