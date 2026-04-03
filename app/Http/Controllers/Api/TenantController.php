<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kost;
use App\Models\Tenant;
use App\Services\TenantBillingService;
use App\Services\RegionScopeService;
use App\Services\TenantsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function __construct(
        private readonly TenantsService $tenantsService,
        private readonly RegionScopeService $regionScopeService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kost_id' => ['required', 'string', 'exists:kosts,id'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'start_date' => ['required', 'date'],
            'rent_price' => ['required', 'integer', 'min:0'],
            'trash_fee' => ['required', 'integer', 'min:0'],
            'security_fee' => ['required', 'integer', 'min:0'],
            'admin_fee' => ['required', 'integer', 'min:0'],
            'status' => ['required', Rule::in(TenantBillingService::manualStatuses())],
            'dp_amount' => ['nullable', 'integer', 'min:0'],
            'dp_due_date' => ['nullable', 'date'],
        ]);

        $kost = Kost::query()->findOrFail($data['kost_id']);
        abort_unless(
            $request->user() && $this->regionScopeService->canAccessRegion($kost->region_id, $request->user()),
            403,
        );

        $tenant = $this->tenantsService->create([
            ...$data,
            'phone' => $data['phone'] ?: null,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Penyewa berhasil ditambahkan.',
            'tenant' => $tenant,
        ], 201);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        abort_unless(
            $request->user() && $this->regionScopeService->canAccessRegion($tenant->kost?->region_id, $request->user()),
            403,
        );

        $data = $request->validate([
            'kost_id' => ['required', 'string', 'exists:kosts,id'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'start_date' => ['required', 'date'],
            'rent_price' => ['required', 'integer', 'min:0'],
            'trash_fee' => ['required', 'integer', 'min:0'],
            'security_fee' => ['required', 'integer', 'min:0'],
            'admin_fee' => ['required', 'integer', 'min:0'],
            'status' => ['required', Rule::in(TenantBillingService::manualStatuses())],
            'dp_amount' => ['nullable', 'integer', 'min:0'],
            'dp_due_date' => ['nullable', 'date'],
        ]);

        $kost = Kost::query()->findOrFail($data['kost_id']);
        abort_unless(
            $request->user() && $this->regionScopeService->canAccessRegion($kost->region_id, $request->user()),
            403,
        );

        $updatedTenant = $this->tenantsService->update($tenant->id, [
            ...$data,
            'phone' => $data['phone'] ?: null,
        ]);

        return response()->json([
            'message' => 'Data penyewa berhasil diperbarui.',
            'tenant' => $updatedTenant,
        ]);
    }

    public function destroy(Request $request, Tenant $tenant): JsonResponse
    {
        abort_unless(
            $request->user() && $this->regionScopeService->canAccessRegion($tenant->kost?->region_id, $request->user()),
            403,
        );

        $this->tenantsService->delete($tenant->id);

        return response()->json([
            'message' => 'Penyewa berhasil dinonaktifkan.',
        ]);
    }
}
