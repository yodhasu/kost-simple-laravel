<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\TransactionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionsService $transactionsService,
    ) {
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $data = $request->validate([
            'kost_id' => ['nullable', 'string', 'exists:kosts,id'],
            'tenant_id' => ['nullable', 'string', 'exists:tenants,id'],
            'financial_class' => ['required', Rule::in(['REVENUE', 'EXPENSE', 'LIABILITY'])],
            'category' => ['required', 'string', 'max:80'],
            'amount' => ['required', 'integer', 'min:1'],
            'transaction_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $updatedTransaction = $this->transactionsService->updateManualControl($transaction, $data);

        return response()->json([
            'message' => 'Transaksi berhasil diperbarui.',
            'transaction' => $updatedTransaction,
        ]);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        $request->validate([
            'confirmation' => ['required', 'string', Rule::in(['HAPUS'])],
        ], [
            'confirmation.in' => 'Ketik HAPUS untuk menghapus transaksi permanen.',
        ]);

        $this->transactionsService->deleteManualControl($transaction);

        return response()->json([
            'message' => 'Transaksi berhasil dihapus.',
        ]);
    }
}
