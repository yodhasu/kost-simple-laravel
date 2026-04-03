<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardPayloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardPayloadService $dashboardPayloadService,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json($this->dashboardPayloadService->build($request));
    }
}
