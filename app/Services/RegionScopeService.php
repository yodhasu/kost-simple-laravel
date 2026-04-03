<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class RegionScopeService
{
    /**
     * @return array<int, string>|null
     */
    public function accessibleRegionIds(User $user): ?array
    {
        $role = $user->profile?->role;

        if (in_array($role, ['owner', 'it'], true)) {
            return null;
        }

        return $user->regions()->pluck('regions.id')->all();
    }

    public function resolve(?string $requestedRegionId, User $user): ?string
    {
        $assignedIds = $this->accessibleRegionIds($user);

        if ($assignedIds === null) {
            return $requestedRegionId;
        }

        if ($assignedIds === []) {
            return null;
        }

        if ($requestedRegionId && in_array($requestedRegionId, $assignedIds, true)) {
            return $requestedRegionId;
        }

        return $assignedIds[0];
    }

    public function canAccessRegion(?string $regionId, User $user): bool
    {
        if ($regionId === null) {
            return true;
        }

        $assignedIds = $this->accessibleRegionIds($user);

        return $assignedIds === null || in_array($regionId, $assignedIds, true);
    }

    public function scopeRegionColumn(EloquentBuilder|QueryBuilder $query, User $user, string $column = 'region_id'): EloquentBuilder|QueryBuilder
    {
        $assignedIds = $this->accessibleRegionIds($user);

        if ($assignedIds === null) {
            return $query;
        }

        if ($assignedIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $assignedIds);
    }
}
