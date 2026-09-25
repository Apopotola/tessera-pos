<?php

namespace Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Dashboard\Services\DashboardService;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    #[OA\Get(
        path: '/api/v1/dashboard/summary',
        summary: 'Operational snapshot; sections the user may not see are null',
        tags: ['Dashboard'],
        responses: [new OA\Response(response: 200, description: 'Summary')],
    )]
    public function summary(Request $request): JsonResponse
    {
        return $this->success('Dashboard summary.', $this->dashboard->summaryFor($request->user()));
    }
}
