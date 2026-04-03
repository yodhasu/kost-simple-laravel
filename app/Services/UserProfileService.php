<?php

namespace App\Services;

use App\Models\Region;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class UserProfileService
{
    public function getProfileForUser(User $user): ?UserProfile
    {
        return $user->profile;
    }

    public function getProfileForUserOrFail(User $user): UserProfile
    {
        return $user->profile()->firstOrFail();
    }

    public function listAdminAccounts(): Collection
    {
        return User::query()
            ->with(['profile', 'regions'])
            ->whereHas('profile', fn ($query) => $query->whereIn('role', ['admin', 'it']))
            ->orderByDesc(
                UserProfile::query()
                    ->select('created_at')
                    ->whereColumn('user_profiles.user_id', 'users.id')
                    ->limit(1)
            )
            ->get();
    }

    public function getAdminAccountById(string $id): User
    {
        return User::query()
            ->with(['profile', 'regions'])
            ->whereKey($id)
            ->whereHas('profile', fn ($query) => $query->whereIn('role', ['admin', 'it']))
            ->firstOrFail();
    }

    /**
     * @param  array{name: string, email: string, password: string, region_ids: array<int, string>, role: string}  $data
     */
    public function createAdminAccount(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'username' => $data['email'],
                'email' => $data['email'],
                'password_hash' => $data['password'],
            ]);

            $user->profile()->create([
                'name' => $data['name'],
                'role' => $data['role'],
            ]);

            $user->regions()->sync($this->resolveRegionIdsForRole($data['role'], $data['region_ids']));

            return $user->load(['profile', 'regions']);
        });
    }

    /**
     * @param  array<int, string>  $regionIds
     */
    public function updateAdminRegions(User $user, array $regionIds): User
    {
        $user->regions()->sync($regionIds);

        return $user->load(['profile', 'regions']);
    }

    /**
     * @param  array{name: string, email: string, region_ids: array<int, string>, role: string, password?: string|null}  $data
     */
    public function updateAdminAccount(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $user->fill([
                'username' => $data['email'],
                'email' => $data['email'],
            ]);

            if (! empty($data['password'])) {
                $user->password_hash = $data['password'];
            }

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            $user->save();

            $user->profile()->updateOrCreate([
                'user_id' => $user->id,
            ], [
                'name' => $data['name'],
                'role' => $data['role'],
            ]);

            $user->regions()->sync($this->resolveRegionIdsForRole($data['role'], $data['region_ids']));

            return $user->load(['profile', 'regions']);
        });
    }

    public function deleteAdminAccount(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->regions()->detach();
            $user->delete();
        });
    }

    public function assignOwnersToRegion(Region $region): void
    {
        User::query()
            ->whereHas('profile', fn ($query) => $query->whereIn('role', ['owner', 'it']))
            ->get()
            ->each(function (User $user) use ($region): void {
                $user->regions()->syncWithoutDetaching([$region->id]);
            });
    }

    /**
     * @param  array<int, string>  $regionIds
     * @return array<int, string>
     */
    private function resolveRegionIdsForRole(string $role, array $regionIds): array
    {
        if ($role === 'it') {
            return Region::query()->pluck('id')->all();
        }

        return $regionIds;
    }
}
