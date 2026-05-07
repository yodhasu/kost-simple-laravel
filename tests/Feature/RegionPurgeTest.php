<?php

namespace Tests\Feature;

use App\Models\Kost;
use App\Models\Region;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionPurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_purge_tenants_and_transactions_by_region(): void
    {
        $owner = $this->makeUserWithRole('owner');

        $targetRegion = Region::query()->create(['name' => 'Target Region']);
        $otherRegion = Region::query()->create(['name' => 'Other Region']);

        $targetKost = Kost::query()->create([
            'region_id' => $targetRegion->id,
            'name' => 'Kost Target',
            'total_units' => 10,
        ]);

        $otherKost = Kost::query()->create([
            'region_id' => $otherRegion->id,
            'name' => 'Kost Other',
            'total_units' => 10,
        ]);

        $targetTenant = Tenant::query()->create([
            'kost_id' => $targetKost->id,
            'name' => 'Tenant Target',
            'start_date' => '2026-05-01',
            'rent_price' => 1_000_000,
            'status' => 'LUNAS',
            'is_active' => true,
            'trash_fee' => 0,
            'security_fee' => 0,
            'admin_fee' => 0,
        ]);

        $otherTenant = Tenant::query()->create([
            'kost_id' => $otherKost->id,
            'name' => 'Tenant Other',
            'start_date' => '2026-05-01',
            'rent_price' => 1_000_000,
            'status' => 'LUNAS',
            'is_active' => true,
            'trash_fee' => 0,
            'security_fee' => 0,
            'admin_fee' => 0,
        ]);

        Transaction::query()->create([
            'kost_id' => $targetKost->id,
            'tenant_id' => $targetTenant->id,
            'category' => 'rent',
            'amount' => 1_000_000,
            'transaction_date' => '2026-05-01',
            'description' => 'target',
            'region_id' => $targetRegion->id,
            'financial_class' => 'REVENUE',
            'is_frozen' => false,
        ]);

        Transaction::query()->create([
            'kost_id' => $otherKost->id,
            'tenant_id' => $otherTenant->id,
            'category' => 'rent',
            'amount' => 1_000_000,
            'transaction_date' => '2026-05-01',
            'description' => 'other',
            'region_id' => $otherRegion->id,
            'financial_class' => 'REVENUE',
            'is_frozen' => false,
        ]);

        $response = $this->actingAs($owner)
            ->post(route('settings.regions.purge', ['region' => $targetRegion->id, 'tab' => 'region']));

        $response->assertRedirect();

        $this->assertDatabaseMissing('tenants', ['id' => $targetTenant->id]);
        $this->assertDatabaseHas('tenants', ['id' => $otherTenant->id]);

        $this->assertDatabaseMissing('transactions', ['description' => 'target']);
        $this->assertDatabaseHas('transactions', ['description' => 'other']);
    }

    public function test_admin_cannot_purge_region_data(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $region = Region::query()->create(['name' => 'Region']);

        $this->actingAs($admin)
            ->post(route('settings.regions.purge', ['region' => $region->id]))
            ->assertRedirect(route('dashboard'));
    }

    public function test_owner_can_purge_by_kost_only(): void
    {
        $owner = $this->makeUserWithRole('owner');

        $region = Region::query()->create(['name' => 'Region A']);
        $kostOne = Kost::query()->create([
            'region_id' => $region->id,
            'name' => 'Kost One',
            'total_units' => 10,
        ]);
        $kostTwo = Kost::query()->create([
            'region_id' => $region->id,
            'name' => 'Kost Two',
            'total_units' => 10,
        ]);

        $tenantOne = Tenant::query()->create([
            'kost_id' => $kostOne->id,
            'name' => 'Tenant One',
            'start_date' => '2026-05-01',
            'rent_price' => 1_000_000,
            'status' => 'LUNAS',
            'is_active' => true,
            'trash_fee' => 0,
            'security_fee' => 0,
            'admin_fee' => 0,
        ]);

        $tenantTwo = Tenant::query()->create([
            'kost_id' => $kostTwo->id,
            'name' => 'Tenant Two',
            'start_date' => '2026-05-01',
            'rent_price' => 1_000_000,
            'status' => 'LUNAS',
            'is_active' => true,
            'trash_fee' => 0,
            'security_fee' => 0,
            'admin_fee' => 0,
        ]);

        Transaction::query()->create([
            'kost_id' => $kostOne->id,
            'tenant_id' => $tenantOne->id,
            'category' => 'rent',
            'amount' => 1_000_000,
            'transaction_date' => '2026-05-01',
            'description' => 'kost-one',
            'region_id' => $region->id,
            'financial_class' => 'REVENUE',
            'is_frozen' => false,
        ]);

        Transaction::query()->create([
            'kost_id' => $kostTwo->id,
            'tenant_id' => $tenantTwo->id,
            'category' => 'rent',
            'amount' => 1_000_000,
            'transaction_date' => '2026-05-01',
            'description' => 'kost-two',
            'region_id' => $region->id,
            'financial_class' => 'REVENUE',
            'is_frozen' => false,
        ]);

        $response = $this->actingAs($owner)->post(route('settings.purge-data', ['tab' => 'purge']), [
            'scope' => 'kost',
            'kost_id' => $kostOne->id,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseMissing('tenants', ['id' => $tenantOne->id]);
        $this->assertDatabaseHas('tenants', ['id' => $tenantTwo->id]);
        $this->assertDatabaseMissing('transactions', ['description' => 'kost-one']);
        $this->assertDatabaseHas('transactions', ['description' => 'kost-two']);
    }

    private function makeUserWithRole(string $role): User
    {
        $user = User::query()->create([
            'username' => $role.'_user',
            'email' => $role.'@example.com',
            'password_hash' => 'password',
            'email_verified_at' => now(),
        ]);

        UserProfile::query()->create([
            'user_id' => $user->id,
            'name' => ucfirst($role).' User',
            'role' => $role,
        ]);

        return $user;
    }
}
