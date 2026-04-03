<?php

namespace Tests\Feature;

use App\Models\Kost;
use App\Models\Region;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ExportsService;
use App\Services\TenantBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ExportReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_export_generates_monetization_workbook_with_dp_separated_from_income(): void
    {
        $user = User::factory()->create();
        $user->profile()->create([
            'name' => 'Owner Test',
            'role' => 'owner',
        ]);

        $regionA = Region::query()->create(['name' => 'Cengkareng']);
        $regionB = Region::query()->create(['name' => 'Jakarta Pusat']);

        $kostA = Kost::query()->create([
            'region_id' => $regionA->id,
            'name' => 'Kost Alpha',
            'total_units' => 10,
        ]);

        $kostB = Kost::query()->create([
            'region_id' => $regionB->id,
            'name' => 'Kost Beta',
            'total_units' => 8,
        ]);

        $tenantRegular = Tenant::query()->create([
            'kost_id' => $kostA->id,
            'name' => 'Tenant Carry',
            'phone' => '08123',
            'start_date' => '2026-02-01',
            'rent_price' => 100_000,
            'prepaid_balance' => 50_000,
            'paid_until' => '2026-03-01',
            'status' => TenantBillingService::STATUS_BELUM_LUNAS,
            'is_active' => true,
            'trash_fee' => 0,
            'security_fee' => 0,
            'admin_fee' => 0,
        ]);

        $tenantDp = Tenant::query()->create([
            'kost_id' => $kostB->id,
            'name' => 'Tenant DP',
            'phone' => '08124',
            'start_date' => '2026-05-01',
            'dp_due_date' => '2026-04-30',
            'rent_price' => 100_000,
            'prepaid_balance' => 0,
            'paid_until' => null,
            'status' => TenantBillingService::STATUS_DP,
            'is_active' => true,
            'trash_fee' => 0,
            'security_fee' => 0,
            'admin_fee' => 0,
        ]);

        $dpTransaction = Transaction::query()->create([
            'kost_id' => $kostB->id,
            'tenant_id' => $tenantDp->id,
            'category' => 'dp',
            'amount' => 50_000,
            'transaction_date' => '2026-04-01',
            'description' => 'Pembayaran DP penyewa Tenant DP due_date:2026-04-30',
            'region_id' => $regionB->id,
            'financial_class' => 'LIABILITY',
            'is_frozen' => true,
        ]);

        Transaction::query()->create([
            'kost_id' => $kostA->id,
            'tenant_id' => $tenantRegular->id,
            'category' => 'rent',
            'amount' => 50_000,
            'transaction_date' => '2026-04-02',
            'description' => 'Pembayaran sewa | bulan April 2026 | carryover balance Rp50.000',
            'region_id' => $regionA->id,
            'financial_class' => 'REVENUE',
            'is_frozen' => false,
        ]);

        Transaction::query()->create([
            'kost_id' => $kostA->id,
            'tenant_id' => $tenantRegular->id,
            'category' => 'extra_fee',
            'amount' => 10_000,
            'transaction_date' => '2026-04-03',
            'description' => 'Biaya ekstra penyewa Tenant Carry',
            'region_id' => $regionA->id,
            'financial_class' => 'EXPENSE',
            'is_frozen' => false,
        ]);

        $response = app(ExportsService::class)->download([
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
            'region_id' => null,
            'data_types' => ['expenses'],
        ], $user);

        $path = $response->getFile()->getPathname();
        $spreadsheet = IOFactory::load($path);

        $this->assertSame([
            'Ringkasan Eksekutif',
            'Ringkasan Kolektibilitas',
            'Laba Bersih',
            'Piutang Penyewa',
            'Detail Pembayaran',
            'Detail Pengeluaran',
            'Rekonsiliasi',
        ], array_map(fn ($sheet) => $sheet->getTitle(), $spreadsheet->getAllSheets()));

        $executiveSheet = $spreadsheet->getSheetByName('Ringkasan Eksekutif');
        $this->assertSame('Ringkasan Eksekutif', $executiveSheet?->getCell('A1')->getValue());
        $this->assertSame('Total Pembayaran Masuk', $executiveSheet?->getCell('A9')->getValue());
        $this->assertSame('Rp 50.000', $executiveSheet?->getCell('B9')->getValue());
        $this->assertSame('Total DP Masuk', $executiveSheet?->getCell('A10')->getValue());
        $this->assertSame('Rp 50.000', $executiveSheet?->getCell('B10')->getValue());
        $this->assertSame('Pendapatan Bersih', $executiveSheet?->getCell('A13')->getValue());
        $this->assertSame('Rp 40.000', $executiveSheet?->getCell('B13')->getValue());

        $paymentSheet = $spreadsheet->getSheetByName('Detail Pembayaran');
        $this->assertStringStartsWith('2026-04-01', (string) $paymentSheet?->getCell('A8')->getValue());
        $this->assertSame('DP', $paymentSheet?->getCell('E8')->getValue());
        $this->assertStringStartsWith('2026-04-02', (string) $paymentSheet?->getCell('A9')->getValue());
        $this->assertSame('Sewa', $paymentSheet?->getCell('E9')->getValue());
        $this->assertSame(50000, $paymentSheet?->getCell('H9')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($path);

        $this->assertNotNull($dpTransaction->id);
    }
}
