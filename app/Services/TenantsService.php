<?php

namespace App\Services;

use App\Models\Kost;
use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TenantsService
{
    public function __construct(
        private readonly TenantBillingService $tenantBillingService,
    ) {
    }

    public function getAll(
        ?string $kostId = null,
        ?string $regionId = null,
        int $page = 1,
        int $pageSize = 10,
        ?string $search = null,
        ?string $status = null,
    ): LengthAwarePaginator {
        $paginator = Tenant::query()
            ->with(['kost.region'])
            ->when($regionId, function ($query) use ($regionId): void {
                $query->whereHas('kost', fn ($kostQuery) => $kostQuery->where('region_id', $regionId));
            })
            ->when($kostId, fn ($query) => $query->where('kost_id', $kostId))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($search, function ($query) use ($search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->latest('created_at')
            ->paginate($pageSize, ['*'], 'page', $page);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Tenant $tenant) => $this->attachComputedFields($tenant)),
        );

        return $paginator;
    }

    public function getById(string $tenantId): Tenant
    {
        $tenant = Tenant::query()->with(['kost.region', 'transactions'])->findOrFail($tenantId);

        return $this->attachComputedFields($tenant);
    }

    public function create(array $data): Tenant
    {
        return DB::transaction(function () use ($data): Tenant {
            $dpAmount = $data['dp_amount'] ?? null;
            $dpDueDate = $data['dp_due_date'] ?? null;
            unset($data['dp_amount']);

            $kost = Kost::query()->findOrFail($data['kost_id']);

            if (($data['is_active'] ?? true) && $this->countActiveTenants($kost->id) >= $kost->total_units) {
                throw $this->badRequest("Kost sudah penuh ({$kost->total_units}/{$kost->total_units}).");
            }

            if (($data['status'] ?? TenantBillingService::STATUS_LUNAS) === TenantBillingService::STATUS_DP) {
                $this->ensureDpPayload($dpAmount, $dpDueDate, (int) ($data['rent_price'] ?? 0));
            }

            $manualStatus = $data['status'] ?? TenantBillingService::STATUS_LUNAS;
            $data['prepaid_balance'] = 0;
            $data['paid_until'] = null;
            $data['status'] = $manualStatus;
            $data['dp_due_date'] = $manualStatus === TenantBillingService::STATUS_DP ? $dpDueDate : null;

            $tenant = Tenant::query()->create($data);

            if ($tenant->status === TenantBillingService::STATUS_DP && $dpAmount) {
                Transaction::query()->create([
                    'kost_id' => $tenant->kost_id,
                    'tenant_id' => $tenant->id,
                    'financial_class' => 'LIABILITY',
                    'category' => 'dp',
                    'amount' => $dpAmount,
                    'transaction_date' => $tenant->start_date ?? now()->toDateString(),
                    'description' => 'Pembayaran DP penyewa '.$tenant->name.' due_date:'.Carbon::parse($dpDueDate)->toDateString(),
                    'region_id' => $kost->region_id,
                    'is_frozen' => true,
                ]);

                $this->syncDpStatus($tenant, $dpDueDate);
                $tenant->save();
            } elseif ($manualStatus !== TenantBillingService::STATUS_ON_HOLD) {
                $tenant = $this->tenantBillingService->settlePayment(
                    $tenant,
                    max(0, (int) ($tenant->rent_price ?? 0)),
                    $tenant->start_date ?? now()->toDateString(),
                );

                $tenant->save();

                $rentTransactionId = null;
                $rentAmount = (int) ($tenant->rent_price ?? 0);

                if ($rentAmount > 0) {
                    $rentTransaction = Transaction::query()->create([
                        'kost_id' => $tenant->kost_id,
                        'tenant_id' => $tenant->id,
                        'financial_class' => 'REVENUE',
                        'category' => 'rent',
                        'amount' => $rentAmount,
                        'transaction_date' => $tenant->start_date ?? now()->toDateString(),
                        'description' => 'Pembayaran awal penyewa '.$tenant->name,
                        'region_id' => $kost->region_id,
                        'is_frozen' => false,
                    ]);

                    $rentTransactionId = $rentTransaction->id;
                }

                $this->createExtraFeeTransaction($tenant, $kost->region_id, $rentTransactionId, $tenant->start_date?->toDateString());
            }

            if ($manualStatus === TenantBillingService::STATUS_ON_HOLD) {
                $tenant->status = TenantBillingService::STATUS_ON_HOLD;
                $tenant->save();
            } elseif ($tenant->status !== TenantBillingService::STATUS_DP) {
                $tenant = $this->tenantBillingService->refreshStatus($tenant);
                $tenant->save();
            }

            return $this->getById($tenant->id);
        });
    }

    public function update(string $tenantId, array $data): Tenant
    {
        return DB::transaction(function () use ($tenantId, $data): Tenant {
            $tenant = Tenant::query()->findOrFail($tenantId);
            $oldRentPrice = (int) ($tenant->rent_price ?? 0);
            $dpAmount = $data['dp_amount'] ?? null;
            $dpDueDate = $data['dp_due_date'] ?? null;
            unset($data['dp_amount']);

            $manualStatus = $data['status'] ?? $tenant->status;

            $tenant->fill([
                ...$data,
                'status' => $manualStatus,
                'dp_due_date' => $manualStatus === TenantBillingService::STATUS_DP ? $dpDueDate : null,
            ]);

            if ($tenant->is_active) {
                $kost = Kost::query()->find($tenant->kost_id);

                if ($kost && $this->countActiveTenants($kost->id, $tenant->id) >= $kost->total_units) {
                    throw $this->badRequest("Kost sudah penuh ({$kost->total_units}/{$kost->total_units}).");
                }
            }

            if ($tenant->status === TenantBillingService::STATUS_DP && ($dpAmount !== null || $dpDueDate !== null)) {
                $dpTransaction = Transaction::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('category', 'dp')
                    ->where('is_frozen', true)
                    ->latest('transaction_date')
                    ->latest('created_at')
                    ->first();

                $effectiveAmount = $dpAmount ?? $dpTransaction?->amount ?? 0;
                $effectiveDueDate = $dpDueDate ?? $this->extractDueDateFromDescription($dpTransaction?->description);
                $this->ensureDpPayload((int) $effectiveAmount, $effectiveDueDate, (int) ($tenant->rent_price ?? 0));

                $kost = Kost::query()->find($tenant->kost_id);

                if ($dpTransaction) {
                    $dpTransaction->fill([
                        'amount' => $effectiveAmount,
                        'description' => 'Pembayaran DP penyewa '.$tenant->name.' due_date:'.Carbon::parse($effectiveDueDate)->toDateString(),
                        'financial_class' => 'LIABILITY',
                        'is_frozen' => true,
                    ])->save();
                } else {
                    Transaction::query()->create([
                        'kost_id' => $tenant->kost_id,
                        'tenant_id' => $tenant->id,
                        'financial_class' => 'LIABILITY',
                        'category' => 'dp',
                        'amount' => $effectiveAmount,
                        'transaction_date' => $tenant->start_date ?? now()->toDateString(),
                        'description' => 'Pembayaran DP penyewa '.$tenant->name.' due_date:'.Carbon::parse($effectiveDueDate)->toDateString(),
                        'region_id' => $kost?->region_id,
                        'is_frozen' => true,
                    ]);
                }

                $this->syncDpStatus($tenant, $effectiveDueDate);
            } elseif ($tenant->status !== TenantBillingService::STATUS_DP) {
                if ($manualStatus === TenantBillingService::STATUS_ON_HOLD) {
                    $tenant->status = TenantBillingService::STATUS_ON_HOLD;
                } else {
                    $tenant->status = TenantBillingService::STATUS_LUNAS;
                }
            }

            $tenant->save();

            if ($tenant->status !== TenantBillingService::STATUS_DP && $tenant->status !== TenantBillingService::STATUS_ON_HOLD && $oldRentPrice !== (int) ($tenant->rent_price ?? 0)) {
                $tenant = $this->tenantBillingService->repriceFutureCoverage($tenant, $oldRentPrice);
                $tenant->save();
            }

            if ($tenant->status !== TenantBillingService::STATUS_DP && $tenant->status !== TenantBillingService::STATUS_ON_HOLD) {
                $tenant = $this->tenantBillingService->refreshStatus($tenant);
                $tenant->save();
            }

            return $this->getById($tenant->id);
        });
    }

    public function delete(string $tenantId): void
    {
        DB::transaction(function () use ($tenantId): void {
            $tenant = Tenant::query()->findOrFail($tenantId);

            if ($tenant->status === TenantBillingService::STATUS_DP) {
                $dpTransaction = Transaction::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('category', 'dp')
                    ->where('is_frozen', true)
                    ->latest('transaction_date')
                    ->latest('created_at')
                    ->first();

                if ($dpTransaction) {
                    $dpTransaction->fill([
                        'is_frozen' => false,
                        'financial_class' => 'REVENUE',
                    ])->save();
                }
            }

            $tenant->is_active = false;
            $tenant->end_date = now()->toDateString();
            $tenant->save();
        });
    }

    private function createExtraFeeTransaction(Tenant $tenant, ?string $regionId, ?string $referenceId = null, ?string $transactionDate = null): void
    {
        $extraFees = (int) ($tenant->trash_fee ?? 0)
            + (int) ($tenant->security_fee ?? 0)
            + (int) ($tenant->admin_fee ?? 0);

        if ($extraFees <= 0) {
            return;
        }

        Transaction::query()->create([
            'kost_id' => $tenant->kost_id,
            'tenant_id' => $tenant->id,
            'financial_class' => 'EXPENSE',
            'category' => 'extra_fee',
            'amount' => $extraFees,
            'transaction_date' => $transactionDate ?? now()->toDateString(),
            'description' => 'Biaya ekstra penyewa '.$tenant->name,
            'region_id' => $regionId,
            'is_frozen' => false,
            'reference_id' => $referenceId,
        ]);
    }

    private function countActiveTenants(string $kostId, ?string $excludeTenantId = null): int
    {
        return Tenant::query()
            ->where('kost_id', $kostId)
            ->where('is_active', true)
            ->when($excludeTenantId, fn ($query) => $query->where('id', '!=', $excludeTenantId))
            ->count();
    }

    private function extractDueDateFromDescription(?string $description): ?string
    {
        if (! $description || ! preg_match('/due_date:(\d{4}-\d{2}-\d{2})/', $description, $matches)) {
            return null;
        }

        return $matches[1] ?? null;
    }

    private function ensureDpPayload(?int $dpAmount, mixed $dpDueDate, int $rentPrice): void
    {
        if ($dpAmount === null || $dpAmount <= 0) {
            throw $this->badRequest('dp_amount must be greater than 0 when status is DP');
        }

        if ($dpAmount >= $rentPrice) {
            throw $this->badRequest('Nominal DP harus lebih kecil dari biaya sewa bulanan.');
        }

        if ($dpDueDate === null || $dpDueDate === '') {
            throw $this->badRequest('dp_due_date is required when status is DP');
        }
    }

    private function attachComputedFields(Tenant $tenant): Tenant
    {
        if (($tenant->is_active || $this->tenantBillingService->isDp($tenant)) && ! $this->tenantBillingService->isOnHold($tenant)) {
            $tenant = $this->tenantBillingService->refreshTrackedStatus($tenant);
        }

        $dpTransaction = Transaction::query()
            ->where('tenant_id', $tenant->id)
            ->where('category', 'dp')
            ->where('is_frozen', true)
            ->latest('transaction_date')
            ->latest('created_at')
            ->first();

        $dpDueDate = $this->extractDueDateFromDescription($dpTransaction?->description);
        $tenant->dp_due_date = $dpDueDate;
        $isDp = $dpTransaction !== null;
        $dpPaidAmount = $this->tenantBillingService->dpPaidTotal($tenant);
        $dpRemainingAmount = $this->tenantBillingService->dpRemainingAmount($tenant);

        $tenant->setAttribute('dp_amount', $dpTransaction?->amount);
        $tenant->setAttribute('dp_paid_amount', $dpPaidAmount);
        $tenant->setAttribute('dp_remaining_amount', $dpRemainingAmount);
        $tenant->setAttribute('is_dp', $isDp);

        if ($isDp) {
            $tenant->setAttribute(
                'status',
                $this->tenantBillingService->resolveDpStatus($tenant),
            );
        }

        $tenant->setAttribute('kost_name', $tenant->kost?->name);
        $tenant->setAttribute('region_name', $tenant->kost?->region?->name);
        $tenant->setAttribute('prepaid_balance', (int) ($tenant->prepaid_balance ?? 0));
        $tenant->setAttribute('paid_until', $tenant->paid_until?->toDateString());
        $tenant->setAttribute('next_billing_date', $this->tenantBillingService->nextBillingDate($tenant)?->toDateString());
        $tenant->setAttribute('current_due_amount', $this->tenantBillingService->currentDueAmount($tenant));
        $tenant->setAttribute('total_outstanding_amount', $this->tenantBillingService->totalOutstandingAmount($tenant));

        return $tenant;
    }

    private function syncDpStatus(Tenant $tenant, ?string $dpDueDate): void
    {
        $tenant->dp_due_date = $dpDueDate;
        $tenant->status = $this->tenantBillingService->resolveDpStatus($tenant);
    }

    private function badRequest(string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
        ], Response::HTTP_BAD_REQUEST));
    }
}
