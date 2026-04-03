<?php

namespace App\Services;

use App\Models\Kost;
use App\Models\Tenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

class KostsService
{
    public function getAll(int $page = 1, int $pageSize = 10, ?string $regionId = null)
    {
        return Kost::query()
            ->when($regionId, fn ($query) => $query->where('region_id', $regionId))
            ->latest('created_at')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    public function getById(string $id): Kost
    {
        return Kost::query()->findOrFail($id);
    }

    public function create(array $data): Kost
    {
        return Kost::query()->create($data);
    }

    public function update(string $id, array $data): Kost
    {
        $kost = $this->getById($id);

        if (array_key_exists('total_units', $data) && $data['total_units'] !== null) {
            $activeTenants = Tenant::query()
                ->where('kost_id', $kost->id)
                ->where('is_active', true)
                ->count();

            if ((int) $data['total_units'] < $activeTenants) {
                throw new HttpResponseException(response()->json([
                    'message' => "Jumlah unit tidak boleh kurang dari jumlah penyewa aktif ({$activeTenants}).",
                ], Response::HTTP_BAD_REQUEST));
            }
        }

        $kost->fill($data)->save();

        return $kost;
    }

    public function delete(string $id): void
    {
        $kost = $this->getById($id);
        $activeTenants = Tenant::query()
            ->where('kost_id', $kost->id)
            ->where('is_active', true)
            ->count();

        if ($activeTenants > 0) {
            throw new HttpResponseException(response()->json([
                'message' => 'Kost masih memiliki penyewa aktif dan tidak bisa dihapus.',
            ], Response::HTTP_BAD_REQUEST));
        }

        $kost->delete();
    }
}
