<?php

namespace App\Services;

use App\Models\Region;
use Illuminate\Http\Request;

class DashboardPayloadService
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly RegionScopeService $regionScopeService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Request $request): array
    {
        $selectedRegionId = $this->resolveRegionFilter($request);
        $summary = $this->dashboardService->getSummary(regionId: $selectedRegionId);

        return [
            'viewer' => $this->viewer($request),
            'regions' => $this->regions($request),
            'selectedRegionId' => $selectedRegionId ?? 'all',
            'stats' => [
                'todayNetRevenue' => $summary['stats']['net_revenue_to_date'],
                'tenantChangePercent' => $summary['stats']['tenant_change_percent'] ?? 0,
                'dpTotal' => $summary['dp_total'],
                'dpCount' => $summary['dp_count'],
                'activeTenants' => $summary['stats']['total_tenants'],
                'emptyRooms' => $summary['stats']['empty_rooms'],
                'totalRooms' => $summary['stats']['total_rooms'],
                'overdueTenants' => $summary['stats']['overdue_tenants'],
                'overdueByKost' => $summary['stats']['overdue_by_kost'],
            ],
            'trendBars' => $summary['trend_bars']['items'],
            'financeOverview' => $summary['finance_overview'],
        ];
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private function regions(Request $request): array
    {
        $user = $request->user();
        $assignedIds = $user ? $this->regionScopeService->accessibleRegionIds($user) : null;

        $query = Region::query()->latest('created_at');

        if ($assignedIds !== null) {
            $query->whereIn('id', $assignedIds);
        }

        $regionList = $query->get(['id', 'name'])->map(fn (Region $region) => [
            'id' => $region->id,
            'name' => $region->name,
        ])->all();

        if ($assignedIds === null || count($regionList) > 1) {
            array_unshift($regionList, ['id' => 'all', 'name' => 'Semua Region']);
        }

        return $regionList;
    }

    /**
     * @return array{name: string, email: string, role: string}
     */
    private function viewer(Request $request): array
    {
        return [
            'name' => $request->user()?->profile?->name ?? $request->user()?->username ?? 'Owner Kost',
            'email' => $request->user()?->email ?? 'owner@kost.local',
            'role' => $request->user()?->profile?->role ?? 'owner',
        ];
    }

    private function resolveRegionFilter(Request $request): ?string
    {
        $requestedRegionId = $request->string('region_id')->toString() ?: null;

        if ($requestedRegionId === 'all') {
            $requestedRegionId = null;
        }

        return $request->user()
            ? $this->regionScopeService->resolve($requestedRegionId, $request->user())
            : $requestedRegionId;
    }
}
