<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::query()->updateOrCreate([
            'email' => 'tester@kost.local',
        ], [
            'username' => 'tester',
            'email_verified_at' => Carbon::now(),
            'password_hash' => Hash::make('password123'),
        ]);

        UserProfile::query()->updateOrCreate([
            'user_id' => $user->id,
        ], [
            'id' => (string) Str::uuid(),
            'name' => 'Kost Local Tester',
            'role' => 'owner',
        ]);
    }
}
