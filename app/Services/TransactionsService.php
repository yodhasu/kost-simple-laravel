<?php

namespace App\Services;

use App\Models\Kost;
use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TransactionsService
{
    public function __construct(
        private readonly TenantBillingService $tenantBillingService,
    ) {
    }

    public function createPayment(array $data): Transaction
    {
        return DB::transaction(function () use ($data): Transaction {
            $tenant = Tenant::query()
                ->where('id', $data['tenant_id'])
                ->where('kost_id', $data['kost_id'])
                ->first();

            if (! $tenant) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Tenant not found',
                ], Response::HTTP_NOT_FOUND));
            }

            $kost = Kost::query()->find($data['kost_id']);
            if ($tenant->status === TenantBillingService::STATUS_ON_HOLD) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Tenant dengan status ON HOLD tidak dapat menerima pembayaran reguler.',
                ], Response::HTTP_BAD_REQUEST));
            }

            if ($this->tenantBillingService->isDp($tenant)) {
                $dpTransaction = Transaction::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('category', 'dp')
                    ->latest('transaction_date')
                    ->latest('created_at')
                    ->first();

                $remainingAmount = $this->tenantBillingService->dpRemainingAmount($tenant);

                if ($remainingAmount <= 0) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Pelunasan DP tenant ini sudah terpenuhi.',
                    ], Response::HTTP_BAD_REQUEST));
                }

                $appliedAmount = min((int) $data['amount'], $remainingAmount);

                $rentTransaction = Transaction::query()->create([
                    'kost_id' => $data['kost_id'],
                    'tenant_id' => $data['tenant_id'],
                    'financial_class' => 'REVENUE',
                    'category' => 'rent',
                    'amount' => $appliedAmount,
                    'transaction_date' => $data['transaction_date'],
                    'description' => 'Pelunasan DP dari '.$tenant->name,
                    'region_id' => $kost?->region_id,
                    'is_frozen' => false,
                    'reference_id' => $dpTransaction?->id,
                ]);

                $remainingAfterPayment = max(0, $remainingAmount - $appliedAmount);

                if ($remainingAfterPayment === 0 && $dpTransaction) {
                    $dpTransaction->fill([
                        'is_frozen' => false,
                        'financial_class' => 'REVENUE',
                        'reference_id' => $rentTransaction->id,
                    ])->save();

                    $tenant->status = TenantBillingService::STATUS_LUNAS;
                    $tenant->paid_until = $tenant->start_date?->toDateString();
                    $tenant->prepaid_balance = 0;
                } else {
                    $tenant->status = $this->tenantBillingService->resolveDpStatus($tenant, $data['transaction_date']);
                }

                $tenant->save();

                return $rentTransaction->fresh();
            }

            $currentDueAmount = $this->tenantBillingService->currentDueAmount($tenant, $data['transaction_date']);

            if ($currentDueAmount <= 0 && ! ($data['allow_carryover'] ?? false)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Penyewa sudah LUNAS. Konfirmasi carryover diperlukan sebelum mencatat pembayaran tambahan.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $tenantBeforePayment = $tenant->replicate();
            $tenantBeforePayment->id = $tenant->id;
            $tenantBeforePayment->exists = $tenant->exists;
            $tenantBeforePayment->setRawAttributes($tenant->getAttributes(), true);

            $tenant = $this->tenantBillingService->settlePayment($tenant, (int) $data['amount'], $data['transaction_date']);
            $tenant->save();

            $rentTransaction = Transaction::query()->create([
                'kost_id' => $data['kost_id'],
                'tenant_id' => $data['tenant_id'],
                'financial_class' => 'REVENUE',
                'category' => 'rent',
                'amount' => $data['amount'],
                'transaction_date' => $data['transaction_date'],
                'description' => $this->tenantBillingService->describeRegularPayment(
                    $tenantBeforePayment,
                    $tenant,
                    (int) $data['amount'],
                    $data['transaction_date'],
                ),
                'region_id' => $kost?->region_id,
                'is_frozen' => false,
            ]);

            $extraFees = (int) ($tenant->trash_fee ?? 0)
                + (int) ($tenant->security_fee ?? 0)
                + (int) ($tenant->admin_fee ?? 0);

            if ($extraFees > 0) {
                Transaction::query()->create([
                    'kost_id' => $data['kost_id'],
                    'tenant_id' => $data['tenant_id'],
                    'financial_class' => 'EXPENSE',
                    'category' => 'extra_fee',
                    'amount' => $extraFees,
                    'transaction_date' => $data['transaction_date'],
                    'description' => 'Biaya ekstra penyewa '.$tenant->name,
                    'region_id' => $kost?->region_id,
                    'is_frozen' => false,
                    'reference_id' => $rentTransaction->id,
                ]);
            }

            return $rentTransaction->fresh();
        });
    }

    public function createExpense(array $data): Transaction
    {
        if (empty($data['kost_id']) && empty($data['region_id'])) {
            throw new HttpResponseException(response()->json([
                'message' => 'Either kost_id or region_id must be provided',
            ], Response::HTTP_BAD_REQUEST));
        }

        $regionId = $data['region_id'] ?? null;

        if (! empty($data['kost_id'])) {
            $kost = Kost::query()->find($data['kost_id']);

            if (! $kost) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Kost not found',
                ], Response::HTTP_NOT_FOUND));
            }

            $regionId = $kost->region_id;
        }

        return Transaction::query()->create([
            'kost_id' => $data['kost_id'] ?? null,
            'tenant_id' => null,
            'financial_class' => 'EXPENSE',
            'category' => $data['category'],
            'amount' => $data['amount'],
            'transaction_date' => $data['transaction_date'],
            'description' => $data['description'] ?? null,
            'region_id' => $regionId,
            'is_frozen' => false,
        ]);
    }

    public function updateManualControl(Transaction $transaction, array $data): Transaction
    {
        $kostId = $data['kost_id'] ?? null;
        $tenantId = $data['tenant_id'] ?? null;
        $regionId = null;

        if ($tenantId) {
            $tenant = Tenant::query()->findOrFail($tenantId);

            if ($kostId && $tenant->kost_id !== $kostId) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Tenant tidak sesuai dengan kost yang dipilih.',
                    'errors' => [
                        'tenant_id' => ['Tenant tidak sesuai dengan kost yang dipilih.'],
                    ],
                ], Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $kostId = $tenant->kost_id;
        }

        if ($kostId) {
            $kost = Kost::query()->findOrFail($kostId);
            $regionId = $kost->region_id;
        }

        $transaction->fill([
            'kost_id' => $kostId,
            'tenant_id' => $tenantId,
            'region_id' => $regionId,
            'financial_class' => $data['financial_class'],
            'category' => $data['category'],
            'amount' => (int) $data['amount'],
            'transaction_date' => $data['transaction_date'],
            'description' => $data['description'] ?? null,
        ])->save();

        return $transaction->fresh(['tenant', 'kost', 'region']);
    }

    public function deleteManualControl(Transaction $transaction): void
    {
        $transaction->delete();
    }

    /**
     * @return array{id: string, date: ?string, category: ?string, financialClass: ?string, amount: int, signedAmount: int, description: ?string, tenantId: ?string, tenantName: ?string, kostId: ?string, kostName: ?string, regionId: ?string, regionName: ?string, referenceId: ?string, isFrozen: bool, createdAt: ?string}
     */
    public function toControlPayload(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'date' => $this->dateString($transaction->transaction_date),
            'category' => $transaction->category,
            'financialClass' => $transaction->financial_class,
            'amount' => (int) $transaction->amount,
            'signedAmount' => $transaction->financial_class === 'EXPENSE' ? -1 * (int) $transaction->amount : (int) $transaction->amount,
            'description' => $transaction->description,
            'tenantId' => $transaction->tenant_id,
            'tenantName' => $transaction->tenant?->name,
            'kostId' => $transaction->kost_id,
            'kostName' => $transaction->kost?->name,
            'regionId' => $transaction->region_id,
            'regionName' => $transaction->region?->name,
            'referenceId' => $transaction->reference_id,
            'isFrozen' => (bool) $transaction->is_frozen,
            'createdAt' => $this->dateString($transaction->created_at),
        ];
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return $value ? (string) $value : null;
    }
}
