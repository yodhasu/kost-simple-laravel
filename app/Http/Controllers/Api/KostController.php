<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kost;
use App\Services\KostsService;
use App\Services\RegionScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KostController extends Controller
{
    public function __construct(
        private readonly KostsService $kostsService,
        private readonly RegionScopeService $regionScopeService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'region_id' => ['required', 'string', 'exists:regions,id'],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string'],
            'total_units' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        abort_unless(
            $request->user() && $this->regionScopeService->canAccessRegion($data['region_id'], $request->user()),
            403,
        );

        $kost = $this->kostsService->create($data);

        return response()->json([
            'message' => 'Kost berhasil ditambahkan.',
            'kost' => $kost,
        ], 201);
    }

    public function update(Request $request, Kost $kost): JsonResponse
    {
        abort_unless(
            $request->user() && $this->regionScopeService->canAccessRegion($kost->region_id, $request->user()),
            403,
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string'],
            'total_units' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        $updatedKost = $this->kostsService->update($kost->id, $data);

        return response()->json([
            'message' => 'Data kost berhasil diperbarui.',
            'kost' => $updatedKost,
        ]);
    }

    public function destroy(Request $request, Kost $kost): JsonResponse
    {
        abort_unless(
            $request->user() && $this->regionScopeService->canAccessRegion($kost->region_id, $request->user()),
            403,
        );

        $this->kostsService->delete($kost->id);

        return response()->json([
            'message' => 'Kost berhasil dihapus.',
        ]);
    }
}
