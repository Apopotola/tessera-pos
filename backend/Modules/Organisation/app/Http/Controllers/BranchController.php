<?php

namespace Modules\Organisation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organisation\Http\Resources\BranchResource;
use Modules\Organisation\Services\BranchAccessService;
use OpenApi\Attributes as OA;

class BranchController extends Controller
{
    public function __construct(private readonly BranchAccessService $branchAccess) {}

    #[OA\Get(
        path: '/api/v1/organisation/branches',
        summary: 'Branches the current user may work in',
        tags: ['Organisation'],
        responses: [
            new OA\Response(response: 200, description: 'Branch list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $branches = $this->branchAccess->branchesFor($request->user());

        return $this->success('Branches retrieved.', BranchResource::collection($branches));
    }
}
