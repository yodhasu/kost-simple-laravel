<?php

namespace Tests\Feature;

use App\Models\Kost;
use App\Models\Region;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\DashboardService;
use App\Services\TenantBillingService;
use App\Services\TenantsService;
use App\Services\TransactionsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\TestCase;

class BillingStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpaid_tenant_before_due_date_is_marked_belum_lunas(): void
    {
        CarbonImmutable::setTestNow('2026-04-01 00:00:00');

        $tenant = $this->makeTenant([
            'start_date' => '2026-04-03',
            'paid_until' => null,
            'status' => TenantBillingService::STATUS_LUNAS,
        ]);

        $tenant = app(TenantBillingService::class)->refreshStatus($tenant);

        $this->assertSame(TenantBillingService::STATUS_BELUM_LUNAS, $tenant->status);
    }

    public function test_unpaid_tenant_on_due_date_is_marked_jatuh_tempo(): void
    {
        CarbonImmutable::setTestNow('2026-04-03 00:00:00');

        $tenant = $this->makeTenant([
            'start_date' => '2026-04-03',
            'paid_until' => null,
            'status' => TenantBillingService::STATUS_LUNAS,
        ]);

        $tenant = app(TenantBillingService::class)->refreshStatus($tenant);

        $this->assertSame(TenantBillingService::STATUS_JATUH_TEMPO, $tenant->status);
    }

    public function test_unpaid_tenant_after_due_date_is_marked_telat_bayar(): void
    {
        CarbonImmutable::setTestNow('2026-04-04 00:00:00');

        $tenant = $this->makeTenant([
            'start_date' => '2026-04-03',
            'paid_until' => null,
            'status' => TenantBillingService::STATUS_LUNAS,
        ]);

        $tenant = app(TenantBillingService::class)->refreshStatus($tenant);

        $this->assertSame(TenantBillingService::STATUS_TELAT_BAYAR, $tenant->status);
    }

    public function test_partially_paid_tenant_after_due_date_stays_belum_lunas(): void
    {
        CarbonImmutable::setTestNow('2026-04-04 00:00:00');

        $tenant = $this->makeTenant([
            'start_date' => '2026-04-03',
            'rent_price' => 100_000,
            'paid_until' => '2026-03-03',
            'prepaid_balance' => 50_000,
            'status' => TenantBillingService::STATUS_LUNAS,
        ]);

        $tenant = app(TenantBillingService::class)->refreshStatus($tenant);

        $this->assertSame(TenantBillingService::STATUS_BELUM_LUNAS, $tenant->status);
        $this->assertSame(50_000, app(TenantBillingService::class)->currentDueAmount($tenant));
    }

    public function test_tenant_with_partial_previous_month_and_unpaid_current_month_is_marked_telat_bayar(): void
    {
        CarbonImmutable::setTestNow('2026-04-04 00:00:00');

        $tenant = $this->makeTenant([
            'start_date' => '2026-02-04',
            'rent_price' => 100_000,
            'paid_until' => '2026-02-04',
            'prepaid_balance' => 50_000,
            'status' => TenantBillingService::STATUS_LUNAS,
        ]);

        $tenant = app(TenantBillingService::class)->refreshStatus($tenant);

        $this->assertSame(TenantBillingService::STATUS_TELAT_BAYAR, $tenant->status);
        $this->assertSame(50_000, app(TenantBillingService::class)->currentDueAmount($tenant));
    }

    public function test_total_outstanding_amount_includes_all_unpaid_months_since_last_paid_cycle(): void
    {
        CarbonImmutable::setTestNow('2026-04-04 00:00:00');

        $tenant = $this->makeTenant([
            'start_date' => '2026-01-01',
            'rent_price' => 100_000,
            'paid_until' => '2026-01-01',
            'prepaid_balance' => 0,
            'status' => TenantBillingService::STATUS_LUNAS,
        ]);

        $billing = app(TenantBillingService::class);

        $this->assertSame('2026-02-01', $billing->nextBillingDate($tenant)?->toDateString());
        $this->assertSame(100_000, $billing->currentDueAmount($tenant));
        $this->assertSame(300_000, $billing->totalOutstandingAmount($tenant));
    }

    public function test_overpayment_extends_paid_until_across_multiple_future_months(): void
    {
        CarbonImmutable::setTestNow('2026-04-01 00:00:00');

        $tenant = $this->makeTenant([
            'start_date' => '2026-01-03',
            'paid_until' => '2026-03-03',
            'rent_price' => 150_000,
            'status' => TenantBillingService::STATUS_LUNAS,
        ]);

        $tenant = app(TenantBillingService::class)->settlePayment($tenant, 600_000, '2026-04-01');

        $this->assertSame('2026-07-03', $tenant->paid_until?->toDateString());
        $this->assertSame(0, $tenant->prepaid_balance);
        $this->assertSame(TenantBillingService::STATUS_LUNAS, $tenant->status);
    }

    public function test_partial_future_payment_is_stored_as_prepaid_balance(): void
    {
        CarbonImmutable::setTestNow('2026-04-01 00:00:00');

        $tenant = $this->makeTenant([
            'start_date' => '2026-01-03',
            'paid_until' => '2026-03-03',
            'rent_price' => 150_000,
            'status' => TenantBillingService::STATUS_LUNAS,
        ]);

        $tenant = app(TenantBillingService::class)->settlePayment($tenant, 200_000, '2026-04-01');

        $this->assertSame('2026-04-03', $tenant->paid_until?->toDateString());
        $this->assertSame(50_000, $tenant->prepaid_balance);
        $this->assertSame(TenantBillingService::STATUS_LUNAS, $tenant->status);
    }

    public function test_rent_change_reprices_future_credit_against_new_rent(): void
    {
        CarbonImmutable::setTestNow('2026-04-15 00:00:00');

        $tenant = $this->makeTenant([
            'start_date' => '2026-01-03',
            'paid_until' => '2026-05-03',
            'rent_price' => 400_000,
            'status' => TenantBillingService::STATUS_LUNAS,
        ]);

        $tenant = app(TenantBillingService::class)->repriceFutureCoverage($tenant, 150_000, '2026-04-15');

        $this->assertSame('2026-04-03', $tenant->paid_until?->toDateString());
        $this->assertSame(150_000, $tenant->prepaid_balance);

        $tenant = app(TenantBillingService::class)->refreshStatus($tenant, '2026-05-01');

        $this->assertSame(TenantBillingService::STATUS_BELUM_LUNAS, $tenant->status);
    }

    public function test_dp_status_is_left_unchanged_by_billing_refresh(): void
    {
        CarbonImmutable::setTestNow('2026-04-10 00:00:00');

        $tenant = $this->makeTenant([
            'start_date' => '2026-04-03',
            'status' => TenantBillingService::STATUS_DP,
        ]);

        $tenant = app(TenantBillingService::class)->refreshStatus($tenant);

        $this->assertSame(TenantBillingService::STATUS_DP, $tenant->status);
    }

    public function test_overdue_command_marks_regular_tenant_as_telat_bayar(): void
    {
        CarbonImmutable::setTestNow('2026-04-04 00:00:00');

        $tenant = $this->makeTenant([
            'start_date' => '2026-04-03',
            'paid_until' => null,
            'status' => TenantBillingService::STATUS_LUNAS,
        ]);

        $this->artisan('tenants:check-overdue')
            ->expectsOutput('Checked 1 tenants. Updated 1 status changes for JATUH TEMPO/TELAT BAYAR.')
            ->assertSuccessful();

        $this->assertSame(TenantBillingService::STATUS_TELAT_BAYAR, $tenant->fresh()->status);
    }

    public function test_overdue_command_marks_frozen_dp_as_jatuh_tempo(): void
    {
        CarbonImmutable::setTestNow('2026-04-04 00:00:00');

        $tenant = $this->makeTenant([
            'is_active' => false,
            'status' => TenantBillingService::STATUS_DP,
        ]);

        Transaction::query()->create([
            'kost_id' => $tenant->kost_id,
            'tenant_id' => $tenant->id,
            'financial_class' => 'LIABILITY',
            'category' => 'dp',
            'amount' => 50_000,
            'transaction_date' => '2026-04-01',
            'description' => 'Pembayaran DP penyewa '.$tenant->name.' due_date:2026-04-03',
            'region_id' => $tenant->kost->region_id,
            'is_frozen' => true,
        ]);

        $this->artisan('tenants:check-overdue')
            ->expectsOutput('Checked 1 tenants. Updated 1 status changes for JATUH TEMPO/TELAT BAYAR.')
            ->assertSuccessful();

        $this->assertSame(TenantBillingService::STATUS_JATUH_TEMPO, $tenant->fresh()->status);
    }

    public function test_dp_tenant_creation_saves_dp_due_date_without_virtual_is_dp_column(): void
    {
        $region = Region::query()->create([
            'name' => 'Region DP Test',
        ]);

        $kost = Kost::query()->create([
            'region_id' => $region->id,
            'name' => 'Kost DP Test',
            'total_units' => 10,
        ]);

        $tenant = app(TenantsService::class)->create([
            'kost_id' => $kost->id,
            'name' => 'Tenant DP Test',
            'phone' => '081234567891',
            'start_date' => '2026-05-01',
            'dp_due_date' => '2026-04-30',
            'rent_price' => 100_000,
            'status' => TenantBillingService::STATUS_DP,
            'is_active' => true,
            'trash_fee' => 0,
            'security_fee' => 0,
            'admin_fee' => 0,
            'dp_amount' => 50_000,
        ]);

        $this->assertSame('2026-04-30', $tenant->fresh()->dp_due_date?->toDateString());
        $this->assertDatabaseHas('transactions', [
            'tenant_id' => $tenant->id,
            'category' => 'dp',
            'is_frozen' => true,
        ]);
    }

    public function test_partial_dp_payment_before_due_date_stays_dp_and_reduces_remaining_bill(): void
    {
        CarbonImmutable::setTestNow('2026-04-20 00:00:00');

        $tenant = $this->makeDpTenant('2026-04-30', 50_000, 100_000);

        app(TransactionsService::class)->createPayment([
            'kost_id' => $tenant->kost_id,
            'tenant_id' => $tenant->id,
            'amount' => 25_000,
            'transaction_date' => '2026-04-20',
        ]);

        $tenant->refresh();

        $this->assertSame(TenantBillingService::STATUS_DP, $tenant->status);
        $this->assertSame(75_000, app(TenantBillingService::class)->dpPaidTotal($tenant));
        $this->assertSame(25_000, app(TenantBillingService::class)->dpRemainingAmount($tenant));
        $this->assertTrue(
            Transaction::query()
                ->where('tenant_id', $tenant->id)
                ->where('category', 'dp')
                ->where('is_frozen', true)
                ->exists(),
        );
    }

    public function test_dp_payment_is_capped_to_remaining_bill_and_completes_to_lunas(): void
    {
        CarbonImmutable::setTestNow('2026-04-20 00:00:00');

        $tenant = $this->makeDpTenant('2026-04-30', 50_000, 100_000);

        $transaction = app(TransactionsService::class)->createPayment([
            'kost_id' => $tenant->kost_id,
            'tenant_id' => $tenant->id,
            'amount' => 100_000,
            'transaction_date' => '2026-04-20',
        ]);

        $tenant->refresh();

        $this->assertSame(50_000, $transaction->amount);
        $this->assertSame(TenantBillingService::STATUS_LUNAS, $tenant->status);
        $this->assertSame('2026-05-04', $tenant->paid_until?->toDateString());
        $this->assertFalse(
            Transaction::query()
                ->where('tenant_id', $tenant->id)
                ->where('category', 'dp')
                ->where('is_frozen', true)
                ->exists(),
        );
    }

    public function test_regular_payment_description_lists_covered_months_and_remaining_carryover(): void
    {
        CarbonImmutable::setTestNow('2026-04-04 00:00:00');

        $tenant = $this->makeTenant([
            'name' => 'Carry Over Audit',
            'start_date' => '2026-01-01',
            'rent_price' => 100_000,
            'paid_until' => '2026-01-01',
            'prepaid_balance' => 0,
            'status' => TenantBillingService::STATUS_TELAT_BAYAR,
        ]);

        $transaction = app(TransactionsService::class)->createPayment([
            'kost_id' => $tenant->kost_id,
            'tenant_id' => $tenant->id,
            'amount' => 250_000,
            'transaction_date' => '2026-04-04',
        ]);

        $this->assertSame(
            'Pembayaran sewa | bulan Februari 2026, Maret 2026 | carryover balance Rp50.000',
            $transaction->description,
        );
    }

    public function test_dashboard_income_excludes_dp_money_and_counts_only_regular_rent_income(): void
    {
        CarbonImmutable::setTestNow('2026-04-04 00:00:00');

        $tenant = $this->makeTenant([
            'rent_price' => 100_000,
            'paid_until' => '2026-02-01',
            'prepaid_balance' => 50_000,
            'status' => TenantBillingService::STATUS_BELUM_LUNAS,
        ]);

        Transaction::query()->create([
            'kost_id' => $tenant->kost_id,
            'tenant_id' => $tenant->id,
            'financial_class' => 'REVENUE',
            'category' => 'rent',
            'amount' => 50_000,
            'transaction_date' => '2026-04-04',
            'description' => 'Pembayaran sewa dari '.$tenant->name,
            'region_id' => $tenant->kost->region_id,
            'is_frozen' => false,
        ]);

        $dpTenantOne = $this->makeDpTenant('2026-04-30', 50_000, 100_000);
        $dpTenantTwo = $this->makeDpTenant('2026-04-30', 50_000, 100_000);

        $dpTransaction = Transaction::query()
            ->where('tenant_id', $dpTenantOne->id)
            ->where('category', 'dp')
            ->firstOrFail();

        Transaction::query()->create([
            'kost_id' => $dpTenantOne->kost_id,
            'tenant_id' => $dpTenantOne->id,
            'financial_class' => 'REVENUE',
            'category' => 'rent',
            'amount' => 50_000,
            'transaction_date' => '2026-04-04',
            'description' => 'Pelunasan DP dari '.$dpTenantOne->name,
            'region_id' => $dpTenantOne->kost->region_id,
            'is_frozen' => false,
            'reference_id' => $dpTransaction->id,
        ]);

        $dpTransaction->fill([
            'financial_class' => 'REVENUE',
            'is_frozen' => false,
        ])->save();

        $summary = app(DashboardService::class)->getSummary(regionId: $tenant->kost->region_id);

        $this->assertSame(50_000, $summary['finance_overview']['income_total']);
        $this->assertSame(50_000, $summary['stats']['net_revenue_to_date']);
    }

    public function test_dp_amount_must_be_less_than_monthly_rent(): void
    {
        $region = Region::query()->create([
            'name' => 'Region DP Max',
        ]);

        $kost = Kost::query()->create([
            'region_id' => $region->id,
            'name' => 'Kost DP Max',
            'total_units' => 10,
        ]);

        try {
            app(TenantsService::class)->create([
                'kost_id' => $kost->id,
                'name' => 'Tenant DP Invalid',
                'phone' => '081234567892',
                'start_date' => '2026-05-01',
                'dp_due_date' => '2026-04-30',
                'rent_price' => 100_000,
                'status' => TenantBillingService::STATUS_DP,
                'is_active' => true,
                'trash_fee' => 0,
                'security_fee' => 0,
                'admin_fee' => 0,
                'dp_amount' => 100_000,
            ]);

            $this->fail('Expected DP validation to reject dp_amount that reaches monthly rent.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(
                'Nominal DP harus lebih kecil dari biaya sewa bulanan.',
                $exception->getResponse()->getData(true)['message'] ?? null,
            );
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTenant(array $overrides = []): Tenant
    {
        $region = Region::query()->create([
            'name' => 'Region Test',
        ]);

        $kost = Kost::query()->create([
            'region_id' => $region->id,
            'name' => 'Kost Test',
            'total_units' => 10,
        ]);

        return Tenant::query()->create([
            'kost_id' => $kost->id,
            'name' => 'Tenant Test',
            'phone' => '081234567890',
            'start_date' => '2026-01-03',
            'rent_price' => 150_000,
            'prepaid_balance' => 0,
            'paid_until' => '2026-01-03',
            'status' => TenantBillingService::STATUS_LUNAS,
            'is_active' => true,
            'trash_fee' => 0,
            'security_fee' => 0,
            'admin_fee' => 0,
            ...$overrides,
        ]);
    }

    private function makeDpTenant(string $dpDueDate, int $dpAmount, int $rentPrice): Tenant
    {
        $tenant = $this->makeTenant([
            'start_date' => '2026-05-04',
            'dp_due_date' => $dpDueDate,
            'rent_price' => $rentPrice,
            'paid_until' => null,
            'status' => TenantBillingService::STATUS_DP,
        ]);

        Transaction::query()->create([
            'kost_id' => $tenant->kost_id,
            'tenant_id' => $tenant->id,
            'financial_class' => 'LIABILITY',
            'category' => 'dp',
            'amount' => $dpAmount,
            'transaction_date' => '2026-04-01',
            'description' => 'Pembayaran DP penyewa '.$tenant->name.' due_date:'.$dpDueDate,
            'region_id' => $tenant->kost->region_id,
            'is_frozen' => true,
        ]);

        return $tenant;
    }
}
