<?php

namespace App\Http\Controllers;

use App\Models\Kost;
use App\Models\Region;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\DashboardPayloadService;
use App\Services\RegionScopeService;
use App\Services\TenantsService;
use App\Services\UserProfileService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KostAppController extends Controller
{
    public function __construct(
        private readonly DashboardPayloadService $dashboardPayloadService,
        private readonly RegionScopeService $regionScopeService,
        private readonly TenantsService $tenantsService,
        private readonly UserProfileService $userProfileService,
    ) {
    }

    public function dashboard(Request $request): Response
    {
        return Inertia::render('KostDashboard', $this->dashboardPayloadService->build($request));
    }

    public function tenants(Request $request): Response
    {
        $selectedRegionId = $this->resolveRegionFilter($request);
        $page = max(1, (int) $request->integer('page', 1));
        $pageSize = min(100, max(1, (int) $request->integer('page_size', 10)));
        $search = $request->string('search')->toString() ?: null;
        $status = $request->string('status')->toString() ?: null;
        $paginator = $this->tenantsService->getAll(
            regionId: $selectedRegionId,
            page: $page,
            pageSize: $pageSize,
            search: $search,
            status: $status,
        );

        return Inertia::render('Tenants/Index', [
            'viewer' => $this->viewer($request),
            'regions' => $this->regions($request),
            'kostOptions' => $this->kostOptions($request),
            'filters' => [
                'search' => $search ?? '',
                'status' => $status ?? '',
                'regionId' => $selectedRegionId ?? 'all',
            ],
            'tenants' => collect($paginator->items())->map(fn ($tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'phone' => $tenant->phone,
                'kostName' => $tenant->kost?->name,
                'regionName' => $tenant->kost?->region?->name,
                'kostId' => $tenant->kost_id,
                'startDate' => $tenant->start_date?->toDateString(),
                'rentPrice' => $tenant->rent_price,
                'trashFee' => (int) ($tenant->trash_fee ?? 0),
                'securityFee' => (int) ($tenant->security_fee ?? 0),
                'adminFee' => (int) ($tenant->admin_fee ?? 0),
                'status' => $tenant->status,
                'dpAmount' => $tenant->dp_amount,
                'dpDueDate' => $tenant->dp_due_date,
                'isActive' => (bool) $tenant->is_active,
            ])->all(),
            'pagination' => [
                'total' => $paginator->total(),
                'currentPage' => $paginator->currentPage(),
                'pageSize' => $paginator->perPage(),
            ],
        ]);
    }

    public function payments(Request $request): Response
    {
        $recentActivities = Transaction::query()
            ->with(['tenant', 'kost'])
            ->latest('transaction_date')
            ->latest('created_at')
            ->limit(6)
            ->get()
            ->map(function (Transaction $transaction): array {
                $tone = match ($transaction->financial_class) {
                    'REVENUE' => 'success',
                    'LIABILITY' => 'info',
                    default => 'warning',
                };

                return [
                    'title' => $transaction->description ?: ($transaction->category ?: 'Transaksi'),
                    'meta' => trim(($transaction->tenant?->name ?? '').' - '.($transaction->kost?->name ?? '')),
                    'amount' => $transaction->financial_class === 'EXPENSE'
                        ? -1 * (int) $transaction->amount
                        : (int) $transaction->amount,
                    'tone' => $tone,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Payments/Index', [
            'viewer' => $this->viewer($request),
            'regions' => $this->regions($request),
            'kostOptions' => $this->kostOptions($request),
            'paymentTenants' => $this->paymentTenants($request),
            'quickActions' => [
                ['title' => 'Tambah Penyewa', 'description' => 'Buat data penyewa baru dan tetapkan kamar.', 'icon' => 'user-plus'],
                ['title' => 'Update Pembayaran', 'description' => 'Catat pembayaran bulanan, tunggakan, atau DP.', 'icon' => 'wallet'],
                ['title' => 'Tambah Pengeluaran', 'description' => 'Input biaya operasional, perbaikan, dan utilitas.', 'icon' => 'receipt'],
                ['title' => 'Tambah Kost', 'description' => 'Tambahkan properti atau unit baru ke sistem.', 'icon' => 'house-plus'],
                ['title' => 'Daftar Kost', 'description' => 'Lihat dan kelola data properti kost Anda.', 'icon' => 'square-pen'],
                ['title' => 'Ekspor Data', 'description' => 'Unduh rekap tenant dan transaksi untuk laporan.', 'icon' => 'download'],
            ],
            'recentActivities' => $recentActivities,
        ]);
    }

    public function export(Request $request): Response
    {
        return Inertia::render('Exports/Index', [
            'viewer' => $this->viewer($request),
            'regions' => $this->regions($request),
            'dataTypes' => [
                ['value' => 'tenants', 'label' => 'Data Penyewa'],
                ['value' => 'payments', 'label' => 'Riwayat Pembayaran'],
                ['value' => 'expenses', 'label' => 'Laporan Keuangan'],
                ['value' => 'control_map', 'label' => 'Peta Kontrol Region & Kost'],
            ],
        ]);
    }

    public function settings(Request $request): Response
    {
        $allRegions = Region::query()
            ->latest('created_at')
            ->get(['id', 'name']);

        $regions = Region::query()
            ->withCount(['kosts as totalUnits' => fn ($query) => $query->select(\DB::raw('coalesce(sum(total_units),0)'))])
            ->withCount(['users as activeAdmins' => fn ($query) => $query->whereHas('profile', fn ($profileQuery) => $profileQuery->where('role', 'admin'))])
            ->latest('created_at')
            ->get()
            ->map(fn (Region $region) => [
                'id' => $region->id,
                'name' => $region->name,
                'totalUnits' => (int) $region->totalUnits,
                'activeAdmins' => (int) $region->activeAdmins,
            ])
            ->all();

        $admins = $this->userProfileService->listAdminAccounts()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->profile?->name ?? $user->username,
                'email' => $user->email,
                'role' => $user->profile?->role ?? 'admin',
                'regionIds' => $user->regions->pluck('id')->values()->all(),
                'region' => $user->regions->pluck('name')->join(', '),
            ])
            ->values()
            ->all();

        return Inertia::render('KostSettings', [
            'viewer' => $this->viewer($request),
            'activeTab' => $request->string('tab')->toString() === 'admin' ? 'admin' : 'region',
            'regions' => $regions,
            'regionOptions' => $allRegions->map(fn (Region $region) => [
                'id' => $region->id,
                'name' => $region->name,
            ])->all(),
            'admins' => $admins,
        ]);
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

    /**
     * @return array<int, array{id: string, name: string, totalUnits: int, occupiedUnits: int, regionId: string, address: ?string, notes: ?string}>
     */
    private function kostOptions(Request $request): array
    {
        $user = $request->user();
        $role = $user?->profile?->role;
        $assignedRegionIds = $user?->regions()->pluck('regions.id')->all() ?? [];

        return Kost::query()
            ->withCount(['tenants as occupiedUnits' => fn ($query) => $query
                ->where('is_active', true)
                ->whereIn('status', ['aktif', 'dp'])])
            ->when($user && ! in_array($role, ['owner', 'it'], true), function ($query) use ($assignedRegionIds): void {
                if ($assignedRegionIds === []) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->whereIn('region_id', $assignedRegionIds);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'total_units'])
            ->map(fn (Kost $kost) => [
                'id' => $kost->id,
                'name' => $kost->name,
                'totalUnits' => (int) $kost->total_units,
                'occupiedUnits' => (int) $kost->occupiedUnits,
                'regionId' => $kost->region_id,
                'address' => $kost->address,
                'notes' => $kost->notes,
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: string, kostId: string, name: string, status: string, rentPrice: int, trashFee: int, securityFee: int, adminFee: int, dpAmount: ?int, dpDueDate: ?string, isActive: bool}>
     */
    private function paymentTenants(Request $request): array
    {
        $user = $request->user();
        $role = $user?->profile?->role;
        $assignedRegionIds = $user?->regions()->pluck('regions.id')->all() ?? [];

        return Tenant::query()
            ->with('kost')
            ->when($user && ! in_array($role, ['owner', 'it'], true), function ($query) use ($assignedRegionIds): void {
                if ($assignedRegionIds === []) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->whereHas('kost', fn ($kostQuery) => $kostQuery->whereIn('region_id', $assignedRegionIds));
            })
            ->latest('created_at')
            ->get()
            ->map(function (Tenant $tenant): array {
                $dpTransaction = Transaction::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('category', 'dp')
                    ->where('is_frozen', true)
                    ->latest('transaction_date')
                    ->latest('created_at')
                    ->first();

                preg_match('/due_date:(\d{4}-\d{2}-\d{2})/', (string) $dpTransaction?->description, $matches);

                return [
                    'id' => $tenant->id,
                    'kostId' => $tenant->kost_id,
                    'name' => $tenant->name,
                    'status' => $tenant->status,
                    'rentPrice' => (int) ($tenant->rent_price ?? 0),
                    'trashFee' => (int) ($tenant->trash_fee ?? 0),
                    'securityFee' => (int) ($tenant->security_fee ?? 0),
                    'adminFee' => (int) ($tenant->admin_fee ?? 0),
                    'dpAmount' => $dpTransaction?->amount ? (int) $dpTransaction->amount : null,
                    'dpDueDate' => $matches[1] ?? null,
                    'isActive' => (bool) $tenant->is_active,
                ];
            })
            ->all();
    }
}
