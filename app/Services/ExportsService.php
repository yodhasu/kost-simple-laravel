<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ExportsService
{
    public function __construct(
        private readonly RegionScopeService $regionScopeService,
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

        $datasets = [];

        foreach ($filters['data_types'] as $type) {
            $datasets[$type] = match ($type) {
                'tenants' => $this->tenantRows($filters['start_date'], $filters['end_date'], $regionId, $user),
                'payments' => $this->paymentRows($filters['start_date'], $filters['end_date'], $regionId, $user),
                'expenses' => $this->expenseRows($filters['start_date'], $filters['end_date'], $regionId, $user),
                'control_map' => $this->controlMapRows($regionId, $user),
                default => [],
            };
        }

        $xlsxPath = tempnam(sys_get_temp_dir(), 'kost-export-');

        if ($xlsxPath === false) {
            throw new HttpResponseException(response()->json([
                'message' => 'Gagal menyiapkan file export.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR));
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($datasets as $type => $rows) {
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
        return $this->baseTransactionQuery($regionId, $user)
            ->whereBetween('transactions.transaction_date', [$startDate, $endDate])
            ->whereIn('transactions.financial_class', ['REVENUE', 'LIABILITY'])
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
    private function expenseRows(string $startDate, string $endDate, ?string $regionId, User $user): array
    {
        return $this->baseTransactionQuery($regionId, $user)
            ->whereBetween('transactions.transaction_date', [$startDate, $endDate])
            ->where('transactions.financial_class', 'EXPENSE')
            ->orderBy('transactions.transaction_date')
            ->get()
            ->map(fn ($row) => [
                'transaction_id' => $row->transaction_id,
                'transaction_date' => $row->transaction_date,
                'region_name' => $row->region_name,
                'kost_name' => $row->kost_name,
                'tenant_name' => $row->tenant_name,
                'category' => $row->category,
                'amount' => (int) $row->amount,
                'description' => $row->description,
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

    private function baseTransactionQuery(?string $regionId, User $user): Builder
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
            'expenses' => 'Pengeluaran',
            'control_map' => 'Kontrol Region',
            default => 'Export',
        };
    }
}
