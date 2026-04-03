<?php

namespace App\Services;

use App\Models\Kost;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ExportsService
{
    public function __construct(
        private readonly RegionScopeService $regionScopeService,
        private readonly TenantBillingService $tenantBillingService,
    ) {
    }

    /**
     * @param  array{start_date: string, end_date: string, region_id?: ?string, data_types: array<int, string>}  $filters
     */
    public function download(array $filters, User $user): Response|BinaryFileResponse
    {
        $regionId = $filters['region_id'] ?? null;

        if ($regionId && ! $this->regionScopeService->canAccessRegion($regionId, $user)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Anda tidak memiliki akses ke region tersebut.',
            ], Response::HTTP_FORBIDDEN));
        }

        $xlsxPath = tempnam(sys_get_temp_dir(), 'kost-export-');

        if ($xlsxPath === false) {
            throw new HttpResponseException(response()->json([
                'message' => 'Gagal menyiapkan file export.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR));
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($filters['data_types'] as $type) {
            if ($type === 'expenses') {
                $this->appendMonetizationReportSheets(
                    $spreadsheet,
                    $filters['start_date'],
                    $filters['end_date'],
                    $regionId,
                    $user,
                );

                continue;
            }

            $rows = match ($type) {
                'tenants' => $this->tenantRows($filters['start_date'], $filters['end_date'], $regionId, $user),
                'payments' => $this->paymentRows($filters['start_date'], $filters['end_date'], $regionId, $user),
                'control_map' => $this->controlMapRows($regionId, $user),
                default => [],
            };

            $sheet = new Worksheet($spreadsheet, $this->sheetTitle($type));
            $spreadsheet->addSheet($sheet);
            $this->fillSheet($sheet, $rows);
        }

        if ($spreadsheet->getSheetCount() === 0) {
            $sheet = new Worksheet($spreadsheet, 'Export');
            $spreadsheet->addSheet($sheet);
            $this->fillSheet($sheet, []);
        }

        $spreadsheet->setActiveSheetIndex(0);
        (new Xlsx($spreadsheet))->save($xlsxPath);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return response()->download(
            $xlsxPath,
            sprintf('kost-export-%s.xlsx', now()->format('Ymd_His')),
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        )->deleteFileAfterSend(true);
    }

    /**
     * @return array<int, array<string, scalar|null>>
     */
    private function tenantRows(string $startDate, string $endDate, ?string $regionId, User $user): array
    {
        return $this->baseTenantQuery($regionId, $user)
            ->whereBetween('tenants.start_date', [$startDate, $endDate])
            ->orderBy('regions.name')
            ->orderBy('kosts.name')
            ->orderBy('tenants.start_date')
            ->get()
            ->map(fn ($row) => [
                'tenant_id' => $row->tenant_id,
                'tenant_name' => $row->tenant_name,
                'phone' => $row->phone,
                'region_name' => $row->region_name,
                'kost_name' => $row->kost_name,
                'start_date' => $row->start_date,
                'end_date' => $row->end_date,
                'status' => $row->status,
                'is_active' => (int) $row->is_active,
                'rent_price' => (int) $row->rent_price,
                'prepaid_balance' => (int) $row->prepaid_balance,
                'paid_until' => $row->paid_until,
                'trash_fee' => (int) $row->trash_fee,
                'security_fee' => (int) $row->security_fee,
                'admin_fee' => (int) $row->admin_fee,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, scalar|null>>
     */
    private function paymentRows(string $startDate, string $endDate, ?string $regionId, User $user): array
    {
        return $this->baseDetailedTransactionQuery($regionId, $user)
            ->whereBetween('transactions.transaction_date', [$startDate, $endDate])
            ->whereIn('transactions.financial_class', ['REVENUE', 'LIABILITY'])
            ->orderBy('regions.name')
            ->orderBy('kosts.name')
            ->orderBy('transactions.transaction_date')
            ->get()
            ->map(fn ($row) => [
                'transaction_id' => $row->transaction_id,
                'transaction_date' => $row->transaction_date,
                'region_name' => $row->region_name,
                'kost_name' => $row->kost_name,
                'tenant_name' => $row->tenant_name,
                'category' => $row->category,
                'financial_class' => $row->financial_class,
                'amount' => (int) $row->amount,
                'description' => $row->description,
                'is_frozen' => (int) $row->is_frozen,
                'reference_id' => $row->reference_id,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, scalar|null>>
     */
    private function controlMapRows(?string $regionId, User $user): array
    {
        $query = DB::table('regions')
            ->leftJoin('user_regions', 'user_regions.region_id', '=', 'regions.id')
            ->leftJoin('users', 'users.id', '=', 'user_regions.user_id')
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->leftJoin('kosts', 'kosts.region_id', '=', 'regions.id')
            ->leftJoin(DB::raw('(select kost_id, count(*) as occupied_units from tenants where is_active = 1 group by kost_id) as active_tenants'), 'active_tenants.kost_id', '=', 'kosts.id')
            ->select([
                'regions.name as region_name',
                'kosts.name as kost_name',
                'kosts.address as kost_address',
                'kosts.total_units',
                DB::raw('coalesce(active_tenants.occupied_units, 0) as occupied_units'),
                'users.username as admin_username',
                'users.email as admin_email',
                'user_profiles.name as admin_name',
                'user_profiles.role as admin_role',
                'user_regions.assigned_at',
            ])
            ->orderBy('regions.name')
            ->orderBy('kosts.name')
            ->orderBy('user_profiles.role')
            ->orderBy('user_profiles.name');

        $this->regionScopeService->scopeRegionColumn($query, $user, 'regions.id');

        if ($regionId) {
            $query->where('regions.id', $regionId);
        }

        return $query->get()
            ->map(fn ($row) => [
                'region_name' => $row->region_name,
                'kost_name' => $row->kost_name,
                'kost_address' => $row->kost_address,
                'total_units' => $row->total_units !== null ? (int) $row->total_units : null,
                'occupied_units' => (int) $row->occupied_units,
                'admin_name' => $row->admin_name,
                'admin_username' => $row->admin_username,
                'admin_email' => $row->admin_email,
                'admin_role' => $row->admin_role,
                'assigned_at' => $row->assigned_at,
            ])
            ->all();
    }

    private function appendMonetizationReportSheets(
        Spreadsheet $spreadsheet,
        string $startDate,
        string $endDate,
        ?string $regionId,
        User $user,
    ): void {
        $context = $this->buildMonetizationContext($startDate, $endDate, $regionId, $user);

        $sheet = new Worksheet($spreadsheet, 'Ringkasan Eksekutif');
        $spreadsheet->addSheet($sheet);
        $this->renderExecutiveSheet($sheet, $context);

        $sheet = new Worksheet($spreadsheet, 'Ringkasan Kolektibilitas');
        $spreadsheet->addSheet($sheet);
        $this->renderTableSheet(
            $sheet,
            'Ringkasan Kolektibilitas',
            $context['meta_lines'],
            ['Region', 'Kost', 'Unit', 'Penyewa Aktif', 'Tagihan Periode', 'Pembayaran Diterima', 'Carryover Balance', 'Tunggakan Akhir', 'DP Aktif', 'Penyewa Jatuh Tempo', 'Penyewa Telat Bayar'],
            $context['collection_rows'],
            currencyColumns: [5, 6, 7, 8, 9],
        );

        $sheet = new Worksheet($spreadsheet, 'Laba Bersih');
        $spreadsheet->addSheet($sheet);
        $this->renderTableSheet(
            $sheet,
            'Laba Bersih',
            $context['meta_lines'],
            ['Region', 'Kost', 'Pendapatan Sewa Terealisasi', 'Pengeluaran Extra Fee Tenant', 'Pengeluaran Operasional', 'Total Pengeluaran', 'Pendapatan Bersih'],
            $context['profit_rows'],
            currencyColumns: [3, 4, 5, 6, 7],
        );

        $sheet = new Worksheet($spreadsheet, 'Piutang Penyewa');
        $spreadsheet->addSheet($sheet);
        $this->renderTableSheet(
            $sheet,
            'Piutang Penyewa',
            $context['meta_lines'],
            ['Region', 'Kost', 'Tenant', 'Tanggal Masuk', 'Status', 'Tagihan Berikutnya', 'Total Tagihan', 'Carryover Balance', 'Paid Until', 'Rent Bulanan', 'Nomor HP'],
            $context['receivable_rows'],
            currencyColumns: [7, 8, 10],
            dateColumns: [4, 6, 9],
        );

        $sheet = new Worksheet($spreadsheet, 'Detail Pembayaran');
        $spreadsheet->addSheet($sheet);
        $this->renderTableSheet(
            $sheet,
            'Detail Pembayaran',
            $context['meta_lines'],
            ['Tanggal', 'Region', 'Kost', 'Tenant', 'Tipe Pembayaran', 'Nilai Masuk', 'Keterangan Alokasi', 'Carryover Balance Setelah Bayar', 'Reference ID'],
            $context['payment_detail_rows'],
            currencyColumns: [6, 8],
            dateColumns: [1],
        );

        $sheet = new Worksheet($spreadsheet, 'Detail Pengeluaran');
        $spreadsheet->addSheet($sheet);
        $this->renderTableSheet(
            $sheet,
            'Detail Pengeluaran',
            $context['meta_lines'],
            ['Tanggal', 'Region', 'Kost', 'Tenant', 'Kategori', 'Jumlah', 'Deskripsi', 'Reference ID'],
            $context['expense_detail_rows'],
            currencyColumns: [6],
            dateColumns: [1],
        );

        $sheet = new Worksheet($spreadsheet, 'Rekonsiliasi');
        $spreadsheet->addSheet($sheet);
        $this->renderTableSheet(
            $sheet,
            'Rekonsiliasi',
            $context['meta_lines'],
            ['Komponen', 'Nilai'],
            $context['reconciliation_rows'],
            currencyColumns: [2],
        );
    }

    /**
     * @return array{
     *   meta_lines: array<int, string>,
     *   summary_kpis: array<int, array<int, string|int>>,
     *   region_summary_rows: array<int, array<int, string|int>>,
     *   collection_rows: array<int, array<int, string|int>>,
     *   profit_rows: array<int, array<int, string|int>>,
     *   receivable_rows: array<int, array<int, string|int|null>>,
     *   payment_detail_rows: array<int, array<int, string|int|null>>,
     *   expense_detail_rows: array<int, array<int, string|int|null>>,
     *   reconciliation_rows: array<int, array<int, string|int>>
     * }
     */
    private function buildMonetizationContext(string $startDate, string $endDate, ?string $regionId, User $user): array
    {
        $periodStart = CarbonImmutable::parse($startDate)->startOfDay();
        $periodEnd = CarbonImmutable::parse($endDate)->startOfDay();

        $tenants = $this->scopedTenantModels($regionId, $user)
            ->get()
            ->map(fn (Tenant $tenant) => $this->tenantBillingService->refreshTrackedStatus($tenant, $periodEnd));

        $kosts = $this->scopedKostModels($regionId, $user)->get();

        $activeCollectionTenants = $tenants->filter(
            fn (Tenant $tenant) => $tenant->is_active
                && ! $this->tenantBillingService->isDp($tenant)
                && ! $this->tenantBillingService->isOnHold($tenant),
        )->values();

        $paymentDetails = $this->buildPaymentDetailRows($startDate, $endDate, $regionId, $user);
        $expenseDetails = $this->buildExpenseDetailRows($startDate, $endDate, $regionId, $user);

        $paymentTotal = array_sum(array_map(fn (array $row) => (int) ($row[5] ?? 0), array_filter(
            $paymentDetails,
            fn (array $row) => ($row[4] ?? null) === 'Sewa',
        )));
        $dpTotal = array_sum(array_map(fn (array $row) => (int) ($row[5] ?? 0), array_filter(
            $paymentDetails,
            fn (array $row) => ($row[4] ?? null) === 'DP',
        )));
        $expenseTotal = array_sum(array_map(fn (array $row) => (int) ($row[5] ?? 0), $expenseDetails));
        $carryoverTotal = $activeCollectionTenants->sum(fn (Tenant $tenant) => max(0, (int) ($tenant->prepaid_balance ?? 0)));
        $outstandingTotal = $activeCollectionTenants->sum(
            fn (Tenant $tenant) => $this->tenantBillingService->totalOutstandingAmount($tenant, $periodEnd),
        );
        $periodBilledTotal = $activeCollectionTenants->sum(
            fn (Tenant $tenant) => $this->billedAmountForPeriod($tenant, $periodStart, $periodEnd),
        );

        $regionSummaries = [];
        $collectionRows = [];
        $profitRows = [];

        foreach ($kosts->sortBy([
            ['region.name', 'asc'],
            ['name', 'asc'],
        ]) as $kost) {
            $kostTenants = $activeCollectionTenants->where('kost_id', $kost->id)->values();
            $kostPaymentTotal = array_sum(array_map(fn (array $row) => (int) $row[5], array_filter(
                $paymentDetails,
                fn (array $row) => ($row[2] ?? null) === $kost->name && ($row[4] ?? null) === 'Sewa',
            )));
            $kostExpenseRows = array_filter($expenseDetails, fn (array $row) => ($row[2] ?? null) === $kost->name);
            $extraFeeExpense = array_sum(array_map(
                fn (array $row) => strtolower((string) ($row[4] ?? '')) === 'extra fee'
                    ? (int) ($row[5] ?? 0)
                    : 0,
                $kostExpenseRows,
            ));
            $operationalExpense = array_sum(array_map(
                fn (array $row) => strtolower((string) ($row[4] ?? '')) !== 'extra fee'
                    ? (int) ($row[5] ?? 0)
                    : 0,
                $kostExpenseRows,
            ));
            $kostExpenseTotal = $extraFeeExpense + $operationalExpense;
            $kostOutstanding = $kostTenants->sum(
                fn (Tenant $tenant) => $this->tenantBillingService->totalOutstandingAmount($tenant, $periodEnd),
            );
            $kostCarryover = $kostTenants->sum(fn (Tenant $tenant) => max(0, (int) ($tenant->prepaid_balance ?? 0)));
            $kostBilled = $kostTenants->sum(
                fn (Tenant $tenant) => $this->billedAmountForPeriod($tenant, $periodStart, $periodEnd),
            );
            $kostDpActive = (int) Transaction::query()
                ->where('kost_id', $kost->id)
                ->where('category', 'dp')
                ->where('is_frozen', true)
                ->where('financial_class', 'LIABILITY')
                ->sum('amount');
            $jatuhTempoCount = $kostTenants->filter(
                fn (Tenant $tenant) => $tenant->status === TenantBillingService::STATUS_JATUH_TEMPO,
            )->count();
            $telatBayarCount = $kostTenants->filter(
                fn (Tenant $tenant) => $tenant->status === TenantBillingService::STATUS_TELAT_BAYAR,
            )->count();

            $collectionRows[] = [
                $kost->region?->name ?? 'Tanpa Region',
                $kost->name,
                (int) $kost->total_units,
                $kostTenants->count(),
                $kostBilled,
                $kostPaymentTotal,
                $kostCarryover,
                $kostOutstanding,
                $kostDpActive,
                $jatuhTempoCount,
                $telatBayarCount,
            ];

            $profitRows[] = [
                $kost->region?->name ?? 'Tanpa Region',
                $kost->name,
                $kostPaymentTotal,
                $extraFeeExpense,
                $operationalExpense,
                $kostExpenseTotal,
                $kostPaymentTotal - $kostExpenseTotal,
            ];

            $regionKey = $kost->region?->name ?? 'Tanpa Region';
            if (! isset($regionSummaries[$regionKey])) {
                $regionSummaries[$regionKey] = [
                    'region' => $regionKey,
                    'tagihan' => 0,
                    'pembayaran' => 0,
                    'dp' => 0,
                    'pengeluaran' => 0,
                    'bersih' => 0,
                    'tunggakan' => 0,
                    'carryover' => 0,
                ];
            }

            $regionSummaries[$regionKey]['tagihan'] += $kostBilled;
            $regionSummaries[$regionKey]['pembayaran'] += $kostPaymentTotal;
            $regionSummaries[$regionKey]['dp'] += $kostDpActive;
            $regionSummaries[$regionKey]['pengeluaran'] += $kostExpenseTotal;
            $regionSummaries[$regionKey]['bersih'] += $kostPaymentTotal - $kostExpenseTotal;
            $regionSummaries[$regionKey]['tunggakan'] += $kostOutstanding;
            $regionSummaries[$regionKey]['carryover'] += $kostCarryover;
        }

        $receivableRows = $activeCollectionTenants
            ->map(function (Tenant $tenant) use ($periodEnd): array {
                $nextBillingDate = $this->tenantBillingService->nextBillingDate($tenant);

                return [
                    $tenant->kost?->region?->name ?? 'Tanpa Region',
                    $tenant->kost?->name ?? 'Tanpa Kost',
                    $tenant->name,
                    optional($tenant->start_date)->toDateString(),
                    $tenant->status,
                    $nextBillingDate?->toDateString(),
                    $this->tenantBillingService->totalOutstandingAmount($tenant, $periodEnd),
                    max(0, (int) ($tenant->prepaid_balance ?? 0)),
                    optional($tenant->paid_until)->toDateString(),
                    (int) ($tenant->rent_price ?? 0),
                    $tenant->phone,
                ];
            })
            ->sort(fn (array $a, array $b) => [$a[5] ?? '9999-12-31', $a[0], $a[1], $a[2]] <=> [$b[5] ?? '9999-12-31', $b[0], $b[1], $b[2]])
            ->values()
            ->all();

        $summaryKpis = [
            ['Total Tagihan Periode', $this->formatCurrencyText($periodBilledTotal)],
            ['Total Pembayaran Masuk', $this->formatCurrencyText($paymentTotal)],
            ['Total DP Masuk', $this->formatCurrencyText($dpTotal)],
            ['Total Carryover Balance Aktif', $this->formatCurrencyText($carryoverTotal)],
            ['Total Pengeluaran', $this->formatCurrencyText($expenseTotal)],
            ['Pendapatan Bersih', $this->formatCurrencyText($paymentTotal - $expenseTotal)],
            ['Total Tunggakan Akhir Periode', $this->formatCurrencyText($outstandingTotal)],
            ['Jumlah Tenant DP', $tenants->filter(fn (Tenant $tenant) => $this->tenantBillingService->isDp($tenant))->count()],
            ['Jumlah Tenant JATUH TEMPO', $tenants->where('status', TenantBillingService::STATUS_JATUH_TEMPO)->count()],
            ['Jumlah Tenant BELUM LUNAS', $tenants->where('status', TenantBillingService::STATUS_BELUM_LUNAS)->count()],
            ['Jumlah Tenant TELAT BAYAR', $tenants->where('status', TenantBillingService::STATUS_TELAT_BAYAR)->count()],
        ];

        $regionSummaryRows = collect($regionSummaries)
            ->sortBy('region')
            ->map(fn (array $row) => [
                $row['region'],
                $row['tagihan'],
                $row['pembayaran'],
                $row['dp'],
                $row['pengeluaran'],
                $row['bersih'],
                $row['tunggakan'],
                $row['carryover'],
            ])
            ->values()
            ->all();

        $reconciliationRows = [
            ['Regular rent cash in', $paymentTotal],
            ['DP cash in', $dpTotal],
            ['Total cash in', $paymentTotal + $dpTotal],
            ['Expense out', $expenseTotal],
            ['Net cash movement', ($paymentTotal + $dpTotal) - $expenseTotal],
            ['Carryover ending balance', $carryoverTotal],
            ['Outstanding ending balance', $outstandingTotal],
        ];

        return [
            'meta_lines' => [
                'Periode: '.$periodStart->toDateString().' s/d '.$periodEnd->toDateString(),
                'Region: '.$this->regionLabel($regionId),
                'Dibuat pada: '.now()->format('Y-m-d H:i:s'),
            ],
            'summary_kpis' => $summaryKpis,
            'region_summary_rows' => $regionSummaryRows,
            'collection_rows' => $collectionRows,
            'profit_rows' => $profitRows,
            'receivable_rows' => $receivableRows,
            'payment_detail_rows' => $paymentDetails,
            'expense_detail_rows' => $expenseDetails,
            'reconciliation_rows' => $reconciliationRows,
        ];
    }

    private function renderExecutiveSheet(Worksheet $sheet, array $context): void
    {
        $this->renderSheetHeader(
            $sheet,
            'Ringkasan Eksekutif',
            'Laporan monetisasi, kolektibilitas, dan profitabilitas berdasarkan logika billing aplikasi.',
            $context['meta_lines'],
        );

        $row = 6;
        $row = $this->renderSectionTable(
            $sheet,
            $row,
            'KPI Utama',
            ['Indikator', 'Nilai'],
            $context['summary_kpis'],
        );

        $this->renderSectionTable(
            $sheet,
            $row + 2,
            'Ringkasan per Region',
            ['Region', 'Tagihan', 'Pembayaran', 'DP Aktif', 'Pengeluaran', 'Pendapatan Bersih', 'Tunggakan', 'Carryover'],
            $context['region_summary_rows'],
            currencyColumns: [2, 3, 4, 5, 6, 7, 8],
        );
    }

    /**
     * @param  array<int, string>  $metaLines
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|null>>  $rows
     * @param  array<int, int>  $currencyColumns
     * @param  array<int, int>  $dateColumns
     */
    private function renderTableSheet(
        Worksheet $sheet,
        string $title,
        array $metaLines,
        array $headers,
        array $rows,
        array $currencyColumns = [],
        array $dateColumns = [],
    ): void {
        $this->renderSheetHeader(
            $sheet,
            $title,
            'Disusun otomatis dari data transaksi, tenant, dan status billing aplikasi.',
            $metaLines,
        );

        $this->renderSectionTable($sheet, 6, 'Data', $headers, $rows, $currencyColumns, $dateColumns);
    }

    /**
     * @param  array<int, string>  $metaLines
     */
    private function renderSheetHeader(Worksheet $sheet, string $title, string $subtitle, array $metaLines): void
    {
        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 16,
                'color' => ['rgb' => '0F172A'],
            ],
        ]);

        $sheet->mergeCells('A2:H2');
        $sheet->setCellValue('A2', $subtitle);
        $sheet->getStyle('A2')->getFont()->getColor()->setRGB('475569');

        foreach ($metaLines as $index => $line) {
            $sheet->mergeCells(sprintf('A%d:H%d', 3 + $index, 3 + $index));
            $sheet->setCellValue(sprintf('A%d', 3 + $index), $line);
            $sheet->getStyle(sprintf('A%d', 3 + $index))->getFont()->getColor()->setRGB('0F766E');
        }
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|null>>  $rows
     * @param  array<int, int>  $currencyColumns
     * @param  array<int, int>  $dateColumns
     */
    private function renderSectionTable(
        Worksheet $sheet,
        int $startRow,
        string $sectionTitle,
        array $headers,
        array $rows,
        array $currencyColumns = [],
        array $dateColumns = [],
    ): int {
        $lastColumnLetter = $this->columnLetter(count($headers));

        $sheet->mergeCells(sprintf('A%d:%s%d', $startRow, $lastColumnLetter, $startRow));
        $sheet->setCellValue(sprintf('A%d', $startRow), $sectionTitle);
        $sheet->getStyle(sprintf('A%d:%s%d', $startRow, $lastColumnLetter, $startRow))->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['rgb' => '0F766E'],
            ],
        ]);

        $headerRow = $startRow + 1;
        $sheet->fromArray($headers, null, sprintf('A%d', $headerRow));
        $sheet->getStyle(sprintf('A%d:%s%d', $headerRow, $lastColumnLetter, $headerRow))->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => '0F172A'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['rgb' => 'CCFBF1'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '99F6E4'],
                ],
            ],
        ]);

        if ($rows === []) {
            $sheet->setCellValue(sprintf('A%d', $headerRow + 1), 'Tidak ada data untuk periode ini.');
            $sheet->freezePane(sprintf('A%d', $headerRow + 1));

            return $headerRow + 1;
        }

        $sheet->fromArray($rows, null, sprintf('A%d', $headerRow + 1));
        $lastDataRow = $headerRow + count($rows);

        $sheet->getStyle(sprintf('A%d:%s%d', $headerRow + 1, $lastColumnLetter, $lastDataRow))->applyFromArray([
            'alignment' => [
                'vertical' => Alignment::VERTICAL_TOP,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'E2E8F0'],
                ],
            ],
        ]);

        foreach ($currencyColumns as $columnIndex) {
            $columnLetter = $this->columnLetter($columnIndex);
            $sheet->getStyle(sprintf('%s%d:%s%d', $columnLetter, $headerRow + 1, $columnLetter, $lastDataRow))
                ->getNumberFormat()
                ->setFormatCode('"Rp" #,##0');
        }

        foreach ($dateColumns as $columnIndex) {
            $columnLetter = $this->columnLetter($columnIndex);
            $sheet->getStyle(sprintf('%s%d:%s%d', $columnLetter, $headerRow + 1, $columnLetter, $lastDataRow))
                ->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
        }

        foreach (range(1, count($headers)) as $columnIndex) {
            $sheet->getColumnDimension($this->columnLetter($columnIndex))->setAutoSize(true);
        }

        $sheet->freezePane(sprintf('A%d', $headerRow + 1));
        $sheet->setAutoFilter(sprintf('A%d:%s%d', $headerRow, $lastColumnLetter, $lastDataRow));

        return $lastDataRow;
    }

    /**
     * @return array<int, array<int, string|int|null>>
     */
    private function buildPaymentDetailRows(string $startDate, string $endDate, ?string $regionId, User $user): array
    {
        $rows = [];

        $regularPayments = $this->baseRegularRevenueQuery($regionId, $user)
            ->whereBetween('transactions.transaction_date', [$startDate, $endDate])
            ->orderBy('transactions.transaction_date')
            ->orderBy('regions.name')
            ->orderBy('kosts.name')
            ->orderBy('tenants.name')
            ->get();

        foreach ($regularPayments as $payment) {
            $rows[] = [
                $payment->transaction_date,
                $payment->region_name,
                $payment->kost_name,
                $payment->tenant_name,
                'Sewa',
                (int) $payment->amount,
                $payment->description,
                $this->extractCarryoverBalance($payment->description),
                $payment->reference_id,
            ];
        }

        $dpPayments = $this->baseDpCashInQuery($regionId, $user)
            ->whereBetween('transactions.transaction_date', [$startDate, $endDate])
            ->get();

        foreach ($dpPayments as $payment) {
            $rows[] = [
                $payment->transaction_date,
                $payment->region_name,
                $payment->kost_name,
                $payment->tenant_name,
                'DP',
                (int) $payment->amount,
                $payment->description,
                0,
                $payment->reference_id,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a[0], $a[1], $a[2], $a[3]] <=> [$b[0], $b[1], $b[2], $b[3]]);

        return $rows;
    }

    /**
     * @return array<int, array<int, string|int|null>>
     */
    private function buildExpenseDetailRows(string $startDate, string $endDate, ?string $regionId, User $user): array
    {
        return $this->baseDetailedTransactionQuery($regionId, $user)
            ->whereBetween('transactions.transaction_date', [$startDate, $endDate])
            ->where('transactions.financial_class', 'EXPENSE')
            ->orderBy('transactions.transaction_date')
            ->orderBy('regions.name')
            ->orderBy('kosts.name')
            ->orderBy('transactions.category')
            ->get()
            ->map(fn ($row) => [
                $row->transaction_date,
                $row->region_name,
                $row->kost_name,
                $row->tenant_name,
                $this->formatCategoryLabel($row->category),
                (int) $row->amount,
                $row->description,
                $row->reference_id,
            ])
            ->all();
    }

    private function baseTenantQuery(?string $regionId, User $user): Builder
    {
        $query = DB::table('tenants')
            ->join('kosts', 'kosts.id', '=', 'tenants.kost_id')
            ->join('regions', 'regions.id', '=', 'kosts.region_id')
            ->select([
                'tenants.id as tenant_id',
                'tenants.name as tenant_name',
                'tenants.phone',
                'tenants.start_date',
                'tenants.end_date',
                'tenants.status',
                'tenants.is_active',
                'tenants.rent_price',
                'tenants.prepaid_balance',
                'tenants.paid_until',
                'tenants.trash_fee',
                'tenants.security_fee',
                'tenants.admin_fee',
                'kosts.name as kost_name',
                'regions.name as region_name',
                'kosts.region_id',
            ]);

        $this->regionScopeService->scopeRegionColumn($query, $user, 'kosts.region_id');

        if ($regionId) {
            $query->where('kosts.region_id', $regionId);
        }

        return $query;
    }

    private function baseDetailedTransactionQuery(?string $regionId, User $user): Builder
    {
        $query = DB::table('transactions')
            ->leftJoin('tenants', 'tenants.id', '=', 'transactions.tenant_id')
            ->leftJoin('kosts', 'kosts.id', '=', 'transactions.kost_id')
            ->leftJoin('regions', 'regions.id', '=', 'transactions.region_id')
            ->select([
                'transactions.id as transaction_id',
                'transactions.transaction_date',
                'transactions.category',
                'transactions.financial_class',
                'transactions.amount',
                'transactions.description',
                'transactions.is_frozen',
                'transactions.reference_id',
                'tenants.name as tenant_name',
                'kosts.name as kost_name',
                'regions.name as region_name',
                'transactions.region_id',
            ]);

        $this->regionScopeService->scopeRegionColumn($query, $user, 'transactions.region_id');

        if ($regionId) {
            $query->where('transactions.region_id', $regionId);
        }

        return $query;
    }

    private function baseRegularRevenueQuery(?string $regionId, User $user): Builder
    {
        return $this->baseDetailedTransactionQuery($regionId, $user)
            ->where('transactions.financial_class', 'REVENUE')
            ->where('transactions.is_frozen', false)
            ->where('transactions.category', '!=', 'dp')
            ->where(function ($query): void {
                $query->whereNull('transactions.reference_id')
                    ->orWhereNotIn('transactions.reference_id', Transaction::query()
                        ->select('id')
                        ->where('category', 'dp'));
            })
            ->where(function ($query): void {
                $query->whereNull('transactions.description')
                    ->orWhere('transactions.description', 'not like', 'Pelunasan DP%');
            });
    }

    private function baseDpCashInQuery(?string $regionId, User $user): Builder
    {
        return $this->baseDetailedTransactionQuery($regionId, $user)
            ->where(function ($query): void {
                $query->where(function ($dpQuery): void {
                    $dpQuery->where('transactions.category', 'dp')
                        ->where('transactions.financial_class', 'LIABILITY');
                })->orWhere(function ($pelunasanQuery): void {
                    $pelunasanQuery->where('transactions.financial_class', 'REVENUE')
                        ->where(function ($innerQuery): void {
                            $innerQuery->whereIn('transactions.reference_id', Transaction::query()
                                ->select('id')
                                ->where('category', 'dp'))
                                ->orWhere('transactions.description', 'like', 'Pelunasan DP%');
                        });
                });
            });
    }

    private function scopedTenantModels(?string $regionId, User $user)
    {
        $query = Tenant::query()->with(['kost.region']);
        $assignedIds = $this->regionScopeService->accessibleRegionIds($user);

        if ($assignedIds !== null) {
            if ($assignedIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('kost', fn ($kostQuery) => $kostQuery->whereIn('region_id', $assignedIds));
            }
        }

        if ($regionId) {
            $query->whereHas('kost', fn ($kostQuery) => $kostQuery->where('region_id', $regionId));
        }

        return $query;
    }

    private function scopedKostModels(?string $regionId, User $user)
    {
        $query = Kost::query()->with('region');
        $assignedIds = $this->regionScopeService->accessibleRegionIds($user);

        if ($assignedIds !== null) {
            if ($assignedIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('region_id', $assignedIds);
            }
        }

        if ($regionId) {
            $query->where('region_id', $regionId);
        }

        return $query;
    }

    private function billedAmountForPeriod(Tenant $tenant, CarbonImmutable $startDate, CarbonImmutable $endDate): int
    {
        if (! $tenant->is_active || $this->tenantBillingService->isDp($tenant) || $this->tenantBillingService->isOnHold($tenant) || ! $tenant->start_date) {
            return 0;
        }

        $rentPrice = max(0, (int) ($tenant->rent_price ?? 0));

        if ($rentPrice <= 0) {
            return 0;
        }

        $count = 0;
        $cursor = CarbonImmutable::parse($tenant->start_date)->startOfDay();
        $tenantEndDate = $tenant->end_date ? CarbonImmutable::parse($tenant->end_date)->startOfDay() : $endDate;
        $effectiveEndDate = $tenantEndDate->lt($endDate) ? $tenantEndDate : $endDate;

        while ($cursor->lte($effectiveEndDate)) {
            if ($cursor->gte($startDate)) {
                $count++;
            }

            $cursor = $this->advanceDueDate(CarbonImmutable::parse($tenant->start_date), $cursor);
        }

        return $count * $rentPrice;
    }

    private function advanceDueDate(CarbonImmutable $startDate, CarbonImmutable $currentDueDate): CarbonImmutable
    {
        $nextMonth = $currentDueDate->addMonthNoOverflow();
        $monthStart = CarbonImmutable::create($nextMonth->year, $nextMonth->month, 1, 0, 0, 0, $startDate->timezone);
        $daysInMonth = $monthStart->daysInMonth;
        $day = min($startDate->day, $daysInMonth);
        $target = $monthStart->day($day);

        if ($startDate->day > $daysInMonth) {
            $target = $target->addDay();
        }

        return $target->startOfDay();
    }

    private function extractCarryoverBalance(?string $description): int
    {
        if (! $description || ! preg_match('/carryover balance Rp([\d\.]+)/i', $description, $matches)) {
            return 0;
        }

        return (int) str_replace('.', '', $matches[1] ?? '0');
    }

    private function regionLabel(?string $regionId): string
    {
        if (! $regionId) {
            return 'Semua Region';
        }

        return (string) (DB::table('regions')->where('id', $regionId)->value('name') ?? 'Region Tidak Diketahui');
    }

    private function columnLetter(int $columnIndex): string
    {
        $letter = '';

        while ($columnIndex > 0) {
            $modulo = ($columnIndex - 1) % 26;
            $letter = chr(65 + $modulo).$letter;
            $columnIndex = intdiv($columnIndex - 1, 26);
        }

        return $letter;
    }

    private function formatCategoryLabel(?string $category): string
    {
        if (! $category) {
            return 'Lainnya';
        }

        return str($category)
            ->replace('_', ' ')
            ->headline()
            ->toString();
    }

    private function formatCurrencyText(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }

    /**
     * @param  array<int, array<string, scalar|null>>  $rows
     */
    private function fillSheet(Worksheet $sheet, array $rows): void
    {
        if ($rows === []) {
            $sheet->setCellValue('A1', 'Tidak ada data.');
            return;
        }

        $headers = array_keys($rows[0]);
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray(array_map('array_values', $rows), null, 'A2');

        $lastColumn = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['rgb' => '0F766E'],
            ],
        ]);

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}1");
    }

    private function sheetTitle(string $type): string
    {
        return match ($type) {
            'tenants' => 'Penyewa',
            'payments' => 'Pembayaran',
            'control_map' => 'Kontrol Region',
            default => 'Export',
        };
    }
}
