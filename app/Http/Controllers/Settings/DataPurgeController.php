<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Kost;
use App\Models\Region;
use App\Services\DataPurgeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DataPurgeController extends Controller
{
    public function __construct(
        private readonly DataPurgeService $dataPurgeService,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['region', 'kost'])],
            'region_id' => ['required_if:scope,region', 'nullable', 'string', 'exists:regions,id'],
            'kost_id' => ['required_if:scope,kost', 'nullable', 'string', 'exists:kosts,id'],
        ]);

        if ($data['scope'] === 'region') {
            $region = Region::query()->findOrFail($data['region_id']);
            $summary = $this->dataPurgeService->purgeRegion($region);

            return back()->with('success', sprintf(
                'Purge region %s selesai. %d tenant dan %d transaksi dihapus.',
                $region->name,
                $summary['tenantsPurged'],
                $summary['transactionsPurged'],
            ));
        }

        $kost = Kost::query()->findOrFail($data['kost_id']);
        $summary = $this->dataPurgeService->purgeKost($kost);

        return back()->with('success', sprintf(
            'Purge kost %s selesai. %d tenant dan %d transaksi dihapus.',
            $kost->name,
            $summary['tenantsPurged'],
            $summary['transactionsPurged'],
        ));
    }
}

