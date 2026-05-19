<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kost;
use App\Services\RegionScopeService;
use App\Services\TransactionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IncomeController extends Controller
{
    public function __construct(
        private readonly TransactionsService $transactionsService,
        private readonly RegionScopeService $regionScopeService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'region_id' => ['nullable', 'string', 'exists:regions,id'],
            'kost_id' => ['nullable', 'string', 'exists:kosts,id'],
            'category' => ['required', Rule::in(['service', 'utility_reimbursement', 'late_fee', 'parking', 'laundry', 'other_income'])],
            'description' => ['nullable', 'string', 'max:500'],
            'amount' => ['required', 'integer', 'min:1'],
            'transaction_date' => ['required', 'date'],
        ]);

        $regionId = $data['region_id'] ?? null;

        if (! empty($data['kost_id'])) {
            $kost = Kost::query()->findOrFail($data['kost_id']);
            $regionId = $kost->region_id;
        }

        abort_unless(
            $request->user() && $this->regionScopeService->canAccessRegion($regionId, $request->user()),
            403,
        );

        $transaction = $this->transactionsService->createIncome([
            ...$data,
            'region_id' => $regionId,
        ]);

        return response()->json([
            'message' => 'Pemasukan berhasil ditambahkan.',
            'transaction' => $transaction,
        ], 201);
    }
}
