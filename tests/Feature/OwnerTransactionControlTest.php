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

class OwnerTransactionControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_transaction_control_page(): void
    {
        $owner = $this->makeUserWithRole('owner');
        [$region, $kost, $tenant] = $this->makeKostFixture();

        Transaction::query()->create([
            'kost_id' => $kost->id,
            'tenant_id' => $tenant->id,
            'category' => 'rent',
            'amount' => 1_000_000,
            'transaction_date' => '2026-05-01',
            'description' => 'Pembayaran sewa Mei',
            'region_id' => $region->id,
            'financial_class' => 'REVENUE',
            'is_frozen' => false,
        ]);

        $this->actingAs($owner)
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transactions/Index')
                ->has('transactions', 1)
                ->where('transactions.0.description', 'Pembayaran sewa Mei')
                ->where('transactions.0.amount', 1_000_000)
                ->where('transactions.0.tenantName', 'Selly')
                ->where('transactions.0.kostName', 'TBKost Sukamto')
            );
    }

    public function test_admin_cannot_view_transaction_control_page(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)
            ->get(route('transactions.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_owner_can_update_transaction(): void
    {
        $owner = $this->makeUserWithRole('owner');
        [$region, $kost, $tenant] = $this->makeKostFixture();

        $transaction = Transaction::query()->create([
            'kost_id' => $kost->id,
            'tenant_id' => $tenant->id,
            'category' => 'rent',
            'amount' => 1_000_000,
            'transaction_date' => '2026-05-01',
            'description' => 'Pembayaran sewa Mei',
            'region_id' => $region->id,
            'financial_class' => 'REVENUE',
            'is_frozen' => false,
        ]);

        $this->actingAs($owner)
            ->patchJson(route('api.transactions.update', $transaction), [
                'transaction_date' => '2026-05-02',
                'financial_class' => 'REVENUE',
                'category' => 'rent_adjustment',
                'amount' => 900_000,
                'description' => 'Koreksi pembayaran sewa Mei',
                'kost_id' => $kost->id,
                'tenant_id' => $tenant->id,
            ])
            ->assertOk()
            ->assertJsonPath('transaction.amount', 900_000)
            ->assertJsonPath('transaction.description', 'Koreksi pembayaran sewa Mei');

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'amount' => 900_000,
            'description' => 'Koreksi pembayaran sewa Mei',
            'category' => 'rent_adjustment',
            'transaction_date' => '2026-05-02 00:00:00',
        ]);
    }

    public function test_owner_can_delete_transaction_permanently(): void
    {
        $owner = $this->makeUserWithRole('owner');
        [$region, $kost, $tenant] = $this->makeKostFixture();

        $transaction = Transaction::query()->create([
            'kost_id' => $kost->id,
            'tenant_id' => $tenant->id,
            'category' => 'rent',
            'amount' => 1_000_000,
            'transaction_date' => '2026-05-01',
            'description' => 'Salah input',
            'region_id' => $region->id,
            'financial_class' => 'REVENUE',
            'is_frozen' => false,
        ]);

        $this->actingAs($owner)
            ->deleteJson(route('api.transactions.destroy', $transaction), [
                'confirmation' => 'HAPUS',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Transaksi berhasil dihapus.');

        $this->assertDatabaseMissing('transactions', [
            'id' => $transaction->id,
        ]);
    }

    public function test_delete_transaction_requires_typed_confirmation(): void
    {
        $owner = $this->makeUserWithRole('owner');
        [$region, $kost, $tenant] = $this->makeKostFixture();

        $transaction = Transaction::query()->create([
            'kost_id' => $kost->id,
            'tenant_id' => $tenant->id,
            'category' => 'rent',
            'amount' => 1_000_000,
            'transaction_date' => '2026-05-01',
            'description' => 'Salah input',
            'region_id' => $region->id,
            'financial_class' => 'REVENUE',
            'is_frozen' => false,
        ]);

        $this->actingAs($owner)
            ->deleteJson(route('api.transactions.destroy', $transaction), [
                'confirmation' => 'hapus',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
        ]);
    }

    public function test_admin_cannot_update_or_delete_transactions(): void
    {
        $admin = $this->makeUserWithRole('admin');
        [$region, $kost, $tenant] = $this->makeKostFixture();

        $transaction = Transaction::query()->create([
            'kost_id' => $kost->id,
            'tenant_id' => $tenant->id,
            'category' => 'rent',
            'amount' => 1_000_000,
            'transaction_date' => '2026-05-01',
            'description' => 'Admin should not control this',
            'region_id' => $region->id,
            'financial_class' => 'REVENUE',
            'is_frozen' => false,
        ]);

        $this->actingAs($admin)
            ->patchJson(route('api.transactions.update', $transaction), [
                'transaction_date' => '2026-05-02',
                'financial_class' => 'REVENUE',
                'category' => 'rent',
                'amount' => 900_000,
                'description' => 'Admin edit',
                'kost_id' => $kost->id,
                'tenant_id' => $tenant->id,
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->deleteJson(route('api.transactions.destroy', $transaction), [
                'confirmation' => 'HAPUS',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'amount' => 1_000_000,
        ]);
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

    /**
     * @return array{0: Region, 1: Kost, 2: Tenant}
     */
    private function makeKostFixture(): array
    {
        $region = Region::query()->create(['name' => 'Sukamto']);
        $kost = Kost::query()->create([
            'region_id' => $region->id,
            'name' => 'TBKost Sukamto',
            'total_units' => 10,
        ]);
        $tenant = Tenant::query()->create([
            'kost_id' => $kost->id,
            'name' => 'Selly',
            'phone' => '08123456789',
            'start_date' => '2026-05-01',
            'rent_price' => 1_000_000,
            'status' => 'LUNAS',
            'is_active' => true,
            'trash_fee' => 0,
            'security_fee' => 0,
            'admin_fee' => 0,
        ]);

        return [$region, $kost, $tenant];
    }
}
