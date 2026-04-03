<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantBillingService;
use Illuminate\Console\Command;

class CheckOverdueTenants extends Command
{
    protected $signature = 'tenants:check-overdue';

    protected $description = 'Refresh tenant billing statuses for JATUH TEMPO and TELAT BAYAR, including overdue DP records';

    public function __construct(
        private readonly TenantBillingService $tenantBillingService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->where(function ($query): void {
                $query
                    ->where('is_active', true)
                    ->orWhereHas('transactions', function ($transactionQuery): void {
                        $transactionQuery
                            ->where('category', 'dp')
                            ->where('is_frozen', true);
                    });
            })
            ->where('status', '!=', TenantBillingService::STATUS_ON_HOLD)
            ->get();

        if ($tenants->isEmpty()) {
            $this->info('No active tenants to check.');

            return self::SUCCESS;
        }

        $updatedCount = 0;

        foreach ($tenants as $tenant) {
            $originalStatus = $tenant->status;

            $tenant = $this->tenantBillingService->refreshTrackedStatus($tenant);

            if ($tenant->status !== $originalStatus) {
                $tenant->save();
                $updatedCount++;
            }
        }

        $this->info("Checked {$tenants->count()} tenants. Updated {$updatedCount} status changes for JATUH TEMPO/TELAT BAYAR.");

        return self::SUCCESS;
    }
}
