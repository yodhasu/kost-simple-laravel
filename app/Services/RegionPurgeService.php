<?php

namespace App\Services;

use App\Models\Region;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class RegionPurgeService
{
    /**
     * @return array{tenantsPurged:int,transactionsPurged:int}
     */
    public function purge(Region $region): array
    {
        return DB::transaction(function () use ($region): array {
            $tenantIds = Tenant::query()
                ->whereHas('kost', fn ($query) => $query->where('region_id', $region->id))
                ->pluck('id');

            $tenantsPurged = $tenantIds->count();

            $transactionQuery = Transaction::query()
                ->where('region_id', $region->id)
                ->when($tenantIds->isNotEmpty(), fn ($query) => $query->orWhereIn('tenant_id', $tenantIds));

            $transactionsPurged = (clone $transactionQuery)->count();

            $transactionQuery->delete();

            if ($tenantIds->isNotEmpty()) {
                Tenant::query()->whereIn('id', $tenantIds)->delete();
            }

            return [
                'tenantsPurged' => $tenantsPurged,
                'transactionsPurged' => $transactionsPurged,
            ];
        });
    }
}

