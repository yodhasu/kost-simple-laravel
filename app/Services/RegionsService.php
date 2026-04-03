<?php

namespace App\Services;

use App\Models\Kost;
use App\Models\Region;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RegionsService
{
    public function __construct(
        private readonly UserProfileService $userProfileService,
    ) {
    }

    public function getAll()
    {
        return Region::query()->latest('created_at')->get();
    }

    public function getById(string $id): Region
    {
        return Region::query()->findOrFail($id);
    }

    public function create(array $data): Region
    {
        return DB::transaction(function () use ($data): Region {
            $region = Region::query()->create($data);
            $this->userProfileService->assignOwnersToRegion($region);

            return $region;
        });
    }

    public function update(string $id, array $data): Region
    {
        $region = $this->getById($id);
        $region->fill($data)->save();

        return $region;
    }

    public function delete(string $id): void
    {
        $region = $this->getById($id);

        $hasKost = Kost::query()->where('region_id', $id)->exists();
        $hasTenants = Tenant::query()
            ->whereHas('kost', fn ($query) => $query->where('region_id', $id))
            ->exists();
        $hasTransactions = Transaction::query()->where('region_id', $id)->exists();

        if ($hasKost || $hasTenants || $hasTransactions) {
            throw new HttpResponseException(response()->json([
                'message' => 'Region masih memiliki data terkait. Hapus data kost, penyewa, dan transaksi terlebih dahulu.',
            ], Response::HTTP_BAD_REQUEST));
        }

        DB::transaction(function () use ($region): void {
            $region->users()->detach();
            $region->delete();
        });
    }
}
