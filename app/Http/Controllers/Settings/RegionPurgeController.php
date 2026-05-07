<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Services\DataPurgeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RegionPurgeController extends Controller
{
    public function __construct(
        private readonly DataPurgeService $dataPurgeService,
    ) {
    }

    public function __invoke(Request $request, Region $region): RedirectResponse
    {
        $summary = $this->dataPurgeService->purgeRegion($region);

        return back()->with('success', sprintf(
            'Purge region %s selesai. %d tenant dan %d transaksi dihapus.',
            $region->name,
            $summary['tenantsPurged'],
            $summary['transactionsPurged'],
        ));
    }
}
