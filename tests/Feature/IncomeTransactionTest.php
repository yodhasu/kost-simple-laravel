<?php

namespace Tests\Feature;

use App\Models\Kost;
use App\Models\Region;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_manual_income_transaction(): void
    {
        $owner = $this->makeUserWithRole('owner');
        $region = Region::query()->create(['name' => 'Sukamto']);
        $kost = Kost::query()->create([
            'region_id' => $region->id,
            'name' => 'TBKost Sukamto',
            'total_units' => 10,
        ]);

        $this->actingAs($owner)
            ->postJson(route('api.incomes.store'), [
                'region_id' => $region->id,
                'kost_id' => $kost->id,
                'category' => 'service',
                'description' => 'Biaya service AC kamar 03',
                'amount' => 175_000,
                'transaction_date' => '2026-05-19',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Pemasukan berhasil ditambahkan.')
            ->assertJsonPath('transaction.financial_class', 'REVENUE')
            ->assertJsonPath('transaction.category', 'service');

        $this->assertDatabaseHas('transactions', [
            'kost_id' => $kost->id,
            'region_id' => $region->id,
            'financial_class' => 'REVENUE',
            'category' => 'service',
            'amount' => 175_000,
            'description' => 'Biaya service AC kamar 03',
        ]);
    }

    public function test_manual_income_counts_in_dashboard_income_summary(): void
    {
        $region = Region::query()->create(['name' => 'Sukamto']);
        $kost = Kost::query()->create([
            'region_id' => $region->id,
            'name' => 'TBKost Sukamto',
            'total_units' => 10,
        ]);

        Transaction::query()->create([
            'kost_id' => $kost->id,
            'tenant_id' => null,
            'financial_class' => 'REVENUE',
            'category' => 'service',
            'amount' => 175_000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Biaya service AC kamar 03',
            'region_id' => $region->id,
            'is_frozen' => false,
        ]);

        $summary = app(DashboardService::class)->getSummary(regionId: $region->id);

        $this->assertSame(175_000, $summary['finance_overview']['income_total']);
        $this->assertSame(175_000, $summary['stats']['net_revenue_to_date']);
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
