<?php

namespace App\Services;

use App\Models\Kost;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TransactionsService
{
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
            $description = $tenant->status === 'dp'
                ? 'Pelunasan DP dari '.$tenant->name
                : 'Pembayaran sewa dari '.$tenant->name;

            $rentTransaction = Transaction::query()->create([
                'kost_id' => $data['kost_id'],
                'tenant_id' => $data['tenant_id'],
                'financial_class' => 'REVENUE',
                'category' => 'rent',
                'amount' => $data['amount'],
                'transaction_date' => $data['transaction_date'],
                'description' => $description,
                'region_id' => $kost?->region_id,
                'is_frozen' => false,
            ]);

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
                        'reference_id' => $rentTransaction->id,
                    ])->save();
                }
            }

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

            if (in_array($tenant->status, ['telat', 'dp'], true)) {
                $tenant->status = 'aktif';
                $tenant->save();
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
}
