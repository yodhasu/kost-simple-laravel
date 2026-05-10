<?php

namespace App\Http\Controllers;

use App\Models\Kost;
use App\Models\Region;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\DashboardPayloadService;
use App\Services\RegionScopeService;
use App\Services\TenantBillingService;
use App\Services\TenantsService;
use App\Services\TransactionsService;
use App\Services\UserProfileService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KostAppController extends Controller
{
    public function __construct(
        private readonly DashboardPayloadService $dashboardPayloadService,
        private readonly RegionScopeService $regionScopeService,
        private readonly TenantBillingService $tenantBillingService,
        private readonly TenantsService $tenantsService,
        private readonly TransactionsService $transactionsService,
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
                'dpPaidAmount' => (int) ($tenant->dp_paid_amount ?? $tenant->dp_amount ?? 0),
                'dpRemainingAmount' => (int) ($tenant->dp_remaining_amount ?? 0),
                'dpDueDate' => $tenant->dp_due_date,
                'isDp' => (bool) ($tenant->is_dp ?? false),
                'prepaidBalance' => (int) ($tenant->prepaid_balance ?? 0),
                'paidUntil' => $tenant->paid_until,
                'nextBillingDate' => $tenant->next_billing_date,
                'currentDueAmount' => (int) ($tenant->current_due_amount ?? 0),
                'totalOutstandingAmount' => (int) ($tenant->total_outstanding_amount ?? 0),
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

    public function transactions(Request $request): Response
    {
        $selectedRegionId = $this->resolveRegionFilter($request);
        $selectedKostId = $request->string('kost_id')->toString() ?: null;
        $selectedClass = $request->string('financial_class')->toString() ?: null;
        $search = $request->string('search')->toString() ?: null;
        $dateFrom = $request->string('date_from')->toString() ?: null;
        $dateTo = $request->string('date_to')->toString() ?: null;

        if ($selectedKostId === 'all') {
            $selectedKostId = null;
        }

        if ($selectedClass === 'all') {
            $selectedClass = null;
        }

        $transactions = Transaction::query()
            ->with(['tenant:id,name,kost_id', 'kost:id,name,region_id', 'region:id,name'])
            ->when($selectedRegionId, fn ($query) => $query->where('region_id', $selectedRegionId))
            ->when($selectedKostId, fn ($query) => $query->where('kost_id', $selectedKostId))
            ->when($selectedClass, fn ($query) => $query->where('financial_class', $selectedClass))
            ->when($dateFrom, fn ($query) => $query->whereDate('transaction_date', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('transaction_date', '<=', $dateTo))
            ->when($search, function ($query) use ($search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery
                        ->where('description', 'like', '%'.$search.'%')
                        ->orWhere('category', 'like', '%'.$search.'%')
                        ->orWhereHas('tenant', fn ($tenantQuery) => $tenantQuery->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('kost', fn ($kostQuery) => $kostQuery->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->latest('transaction_date')
            ->latest('created_at')
            ->limit(200)
            ->get();

        $revenue = $transactions->where('financial_class', 'REVENUE')->sum('amount');
        $expense = $transactions->where('financial_class', 'EXPENSE')->sum('amount');

        return Inertia::render('Transactions/Index', [
            'viewer' => $this->viewer($request),
            'regions' => $this->regions($request),
            'kostOptions' => $this->transactionKostOptions($request),
            'tenantOptions' => $this->transactionTenantOptions($request),
            'filters' => [
                'search' => $search ?? '',
                'regionId' => $selectedRegionId ?? 'all',
                'kostId' => $selectedKostId ?? 'all',
                'financialClass' => $selectedClass ?? 'all',
                'dateFrom' => $dateFrom ?? '',
                'dateTo' => $dateTo ?? '',
            ],
            'summary' => [
                'count' => $transactions->count(),
                'revenue' => (int) $revenue,
                'expense' => (int) $expense,
                'net' => (int) ($revenue - $expense),
            ],
            'transactions' => $transactions
                ->map(fn (Transaction $transaction) => $this->transactionsService->toControlPayload($transaction))
                ->values()
                ->all(),
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
            ->withCount('kosts')
            ->withCount(['users as activeAdmins' => fn ($query) => $query->whereHas('profile', fn ($profileQuery) => $profileQuery->where('role', 'admin'))])
            ->latest('created_at')
            ->get()
            ->map(fn (Region $region) => [
                'id' => $region->id,
                'name' => $region->name,
                'totalKosts' => (int) $region->kosts_count,
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

        $kostOptions = Kost::query()
            ->with('region:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'region_id'])
            ->map(fn (Kost $kost) => [
                'id' => $kost->id,
                'name' => $kost->name,
                'regionId' => $kost->region_id,
                'regionName' => $kost->region?->name,
            ])
            ->values()
            ->all();

        $requestedTab = $request->string('tab')->toString();
        $activeTab = in_array($requestedTab, ['region', 'admin', 'purge'], true) ? $requestedTab : 'region';

        return Inertia::render('KostSettings', [
            'viewer' => $this->viewer($request),
            'activeTab' => $activeTab,
            'regions' => $regions,
            'regionOptions' => $allRegions->map(fn (Region $region) => [
                'id' => $region->id,
                'name' => $region->name,
            ])->all(),
            'admins' => $admins,
            'kostOptions' => $kostOptions,
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
                ->where('is_active', true)])
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
     * @return array<int, array{id: string, kostId: string, name: string, status: string, rentPrice: int, trashFee: int, securityFee: int, adminFee: int, dpAmount: ?int, dpDueDate: ?string, isDp: bool, prepaidBalance: int, paidUntil: ?string, currentDueAmount: int, isActive: bool}>
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
                if (! $this->tenantBillingService->isDp($tenant) && ! $this->tenantBillingService->isOnHold($tenant) && $tenant->is_active) {
                    $tenant = $this->tenantBillingService->refreshStatus($tenant);
                }

                $isDp = $this->tenantBillingService->isDp($tenant);
                $dpAmount = $this->tenantBillingService->dpBaseAmount($tenant);
                $dpPaidAmount = $this->tenantBillingService->dpPaidTotal($tenant);
                $dpRemainingAmount = $this->tenantBillingService->dpRemainingAmount($tenant);

                return [
                    'id' => $tenant->id,
                    'kostId' => $tenant->kost_id,
                    'name' => $tenant->name,
                    'status' => $tenant->status,
                    'rentPrice' => (int) ($tenant->rent_price ?? 0),
                    'trashFee' => (int) ($tenant->trash_fee ?? 0),
                    'securityFee' => (int) ($tenant->security_fee ?? 0),
                    'adminFee' => (int) ($tenant->admin_fee ?? 0),
                    'dpAmount' => $dpAmount > 0 ? $dpAmount : null,
                    'dpPaidAmount' => $dpPaidAmount,
                    'dpRemainingAmount' => $dpRemainingAmount,
                    'dpDueDate' => $tenant->dp_due_date?->toDateString(),
                    'isDp' => $isDp,
                    'prepaidBalance' => (int) ($tenant->prepaid_balance ?? 0),
                    'paidUntil' => $tenant->paid_until?->toDateString(),
                    'nextBillingDate' => $this->tenantBillingService->nextBillingDate($tenant)?->toDateString(),
                    'currentDueAmount' => $this->tenantBillingService->currentDueAmount($tenant),
                    'totalOutstandingAmount' => $this->tenantBillingService->totalOutstandingAmount($tenant),
                    'isActive' => (bool) $tenant->is_active,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string, regionId: string, regionName: ?string}>
     */
    private function transactionKostOptions(Request $request): array
    {
        $user = $request->user();
        $role = $user?->profile?->role;
        $assignedRegionIds = $user?->regions()->pluck('regions.id')->all() ?? [];

        return Kost::query()
            ->with('region:id,name')
            ->when($user && ! in_array($role, ['owner', 'it'], true), function ($query) use ($assignedRegionIds): void {
                if ($assignedRegionIds === []) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->whereIn('region_id', $assignedRegionIds);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'region_id'])
            ->map(fn (Kost $kost) => [
                'id' => $kost->id,
                'name' => $kost->name,
                'regionId' => $kost->region_id,
                'regionName' => $kost->region?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string, kostId: string, kostName: ?string}>
     */
    private function transactionTenantOptions(Request $request): array
    {
        $user = $request->user();
        $role = $user?->profile?->role;
        $assignedRegionIds = $user?->regions()->pluck('regions.id')->all() ?? [];

        return Tenant::query()
            ->with('kost:id,name,region_id')
            ->when($user && ! in_array($role, ['owner', 'it'], true), function ($query) use ($assignedRegionIds): void {
                if ($assignedRegionIds === []) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->whereHas('kost', fn ($kostQuery) => $kostQuery->whereIn('region_id', $assignedRegionIds));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'kost_id'])
            ->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'kostId' => $tenant->kost_id,
                'kostName' => $tenant->kost?->name,
            ])
            ->values()
            ->all();
    }
}
