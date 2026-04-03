<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Services\RegionsService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RegionController extends Controller
{
    public function __construct(
        private readonly RegionsService $regionsService,
    ) {
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
        ]);

        $this->regionsService->create($data);

        return to_route('kost.settings', ['tab' => 'region']);
    }

    public function update(Request $request, Region $region): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
        ]);

        $this->regionsService->update($region->id, $data);

        return to_route('kost.settings', ['tab' => 'region']);
    }

    public function destroy(Region $region): RedirectResponse
    {
        try {
            $this->regionsService->delete($region->id);
        } catch (HttpResponseException $exception) {
            return back()->withErrors([
                'region_delete' => data_get($exception->getResponse()->getData(true), 'message', 'Gagal menghapus region.'),
            ]);
        }

        return to_route('kost.settings', ['tab' => 'region']);
    }
}
