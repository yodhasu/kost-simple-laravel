<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kost;
use App\Services\RegionScopeService;
use App\Services\TransactionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly TransactionsService $transactionsService,
        private readonly RegionScopeService $regionScopeService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kost_id' => ['required', 'string', 'exists:kosts,id'],
            'tenant_id' => ['required', 'string', 'exists:tenants,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'transaction_date' => ['required', 'date'],
        ]);

        $kost = Kost::query()->findOrFail($data['kost_id']);
        abort_unless(
            $request->user() && $this->regionScopeService->canAccessRegion($kost->region_id, $request->user()),
            403,
        );

        $transaction = $this->transactionsService->createPayment($data);

        return response()->json([
            'message' => 'Pembayaran berhasil dicatat.',
            'transaction' => $transaction,
        ], 201);
    }
}
