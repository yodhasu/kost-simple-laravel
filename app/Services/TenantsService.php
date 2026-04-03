<?php

namespace App\Services;

use App\Models\Kost;
use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TenantsService
{
    public function getAll(
        ?string $kostId = null,
        ?string $regionId = null,
        int $page = 1,
        int $pageSize = 10,
        ?string $search = null,
        ?string $status = null,
    ): LengthAwarePaginator {
        return Tenant::query()
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
            unset($data['dp_amount'], $data['dp_due_date']);

            $kost = Kost::query()->findOrFail($data['kost_id']);

            if (($data['is_active'] ?? true) && $this->countActiveTenants($kost->id) >= $kost->total_units) {
                throw $this->badRequest("Kost sudah penuh ({$kost->total_units}/{$kost->total_units}).");
            }

            if (($data['status'] ?? 'aktif') === 'dp') {
                $this->ensureDpPayload($dpAmount, $dpDueDate);
            }

            $tenant = Tenant::query()->create($data);

            if ($tenant->status === 'dp' && $dpAmount) {
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
            } else {
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

                $extraFees = (int) ($tenant->trash_fee ?? 0)
                    + (int) ($tenant->security_fee ?? 0)
                    + (int) ($tenant->admin_fee ?? 0);

                if ($extraFees > 0) {
                    Transaction::query()->create([
                        'kost_id' => $tenant->kost_id,
                        'tenant_id' => $tenant->id,
                        'financial_class' => 'EXPENSE',
                        'category' => 'extra_fee',
                        'amount' => $extraFees,
                        'transaction_date' => $tenant->start_date ?? now()->toDateString(),
                        'description' => 'Biaya ekstra penyewa '.$tenant->name,
                        'region_id' => $kost->region_id,
                        'is_frozen' => false,
                        'reference_id' => $rentTransactionId,
                    ]);
                }
            }

            return $this->getById($tenant->id);
        });
    }

    public function update(string $tenantId, array $data): Tenant
    {
        return DB::transaction(function () use ($tenantId, $data): Tenant {
            $tenant = Tenant::query()->findOrFail($tenantId);
            $dpAmount = $data['dp_amount'] ?? null;
            $dpDueDate = $data['dp_due_date'] ?? null;
            unset($data['dp_amount'], $data['dp_due_date']);

            $tenant->fill($data);

            if ($tenant->is_active) {
                $kost = Kost::query()->find($tenant->kost_id);

                if ($kost && $this->countActiveTenants($kost->id, $tenant->id) >= $kost->total_units) {
                    throw $this->badRequest("Kost sudah penuh ({$kost->total_units}/{$kost->total_units}).");
                }
            }

            $tenant->save();

            if ($tenant->status === 'dp' && ($dpAmount !== null || $dpDueDate !== null)) {
                $dpTransaction = Transaction::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('category', 'dp')
                    ->where('is_frozen', true)
                    ->latest('transaction_date')
                    ->latest('created_at')
                    ->first();

                $effectiveAmount = $dpAmount ?? $dpTransaction?->amount ?? 0;
                $effectiveDueDate = $dpDueDate ?? $this->extractDueDateFromDescription($dpTransaction?->description);
                $this->ensureDpPayload((int) $effectiveAmount, $effectiveDueDate);

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
            }

            return $this->getById($tenant->id);
        });
    }

    public function delete(string $tenantId): void
    {
        DB::transaction(function () use ($tenantId): void {
            $tenant = Tenant::query()->findOrFail($tenantId);

            if ($tenant->status === 'dp') {
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
            $tenant->status = 'inaktif';
            $tenant->end_date = now()->toDateString();
            $tenant->save();
        });
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

    private function ensureDpPayload(?int $dpAmount, mixed $dpDueDate): void
    {
        if ($dpAmount === null || $dpAmount <= 0) {
            throw $this->badRequest('dp_amount must be greater than 0 when status is DP');
        }

        if ($dpDueDate === null || $dpDueDate === '') {
            throw $this->badRequest('dp_due_date is required when status is DP');
        }
    }

    private function attachComputedFields(Tenant $tenant): Tenant
    {
        $dpTransaction = Transaction::query()
            ->where('tenant_id', $tenant->id)
            ->where('category', 'dp')
            ->where('is_frozen', true)
            ->latest('transaction_date')
            ->latest('created_at')
            ->first();

        $tenant->setAttribute('dp_amount', $dpTransaction?->amount);
        $tenant->setAttribute('dp_due_date', $this->extractDueDateFromDescription($dpTransaction?->description));
        $tenant->setAttribute('kost_name', $tenant->kost?->name);
        $tenant->setAttribute('region_name', $tenant->kost?->region?->name);

        return $tenant;
    }

    private function badRequest(string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
        ], Response::HTTP_BAD_REQUEST));
    }
}
