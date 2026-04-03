<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExportsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ExportController extends Controller
{
    public function __construct(
        private readonly ExportsService $exportsService,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'region_id' => ['nullable', 'string', 'exists:regions,id'],
            'data_types' => ['required', 'array', 'min:1'],
            'data_types.*' => ['string', Rule::in(['tenants', 'payments', 'expenses', 'control_map'])],
        ]);

        return $this->exportsService->download([
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'region_id' => $data['region_id'] ?? null,
            'data_types' => $data['data_types'],
        ], $request->user());
    }
}
