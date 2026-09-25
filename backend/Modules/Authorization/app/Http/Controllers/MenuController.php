<?php

namespace Modules\Authorization\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Services\MenuService;
use OpenApi\Attributes as OA;

class MenuController extends Controller
{
    public function __construct(private readonly MenuService $menus) {}

    #[OA\Get(
        path: '/api/v1/authorization/menus',
        summary: 'Navigation tree filtered by the current user\'s permissions',
        tags: ['Authorization'],
        responses: [
            new OA\Response(response: 200, description: 'Menu tree; each item carries a frontend viewType'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->success('Menus retrieved.', $this->menus->treeFor($request->user()));
    }
}
