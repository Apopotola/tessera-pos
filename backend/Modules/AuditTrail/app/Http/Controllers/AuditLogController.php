<?php

namespace Modules\AuditTrail\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AuditTrail\Http\Resources\AuditLogResource;
use Modules\AuditTrail\Models\AuditLog;
use Modules\Authorization\Support\Permissions;
use OpenApi\Attributes as OA;

class AuditLogController extends Controller
{
    #[OA\Get(
        path: '/api/v1/audit-trail/logs',
        summary: 'Paginated audit log (newest first)',
        tags: ['Audit trail'],
        parameters: [
            new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Audit entries'),
            new OA\Response(response: 403, description: 'Missing audit.view permission'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::AUDIT_VIEW), 403);

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = AuditLog::query()
            ->with(['user:id,name', 'approver:id,name'])
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['user_id'] ?? null, fn ($q, $userId) => $q->where('user_id', $userId))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return $this->success('Audit log retrieved.', [
            'items' => AuditLogResource::collection($page->items()),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'lastPage' => $page->lastPage(),
            ],
        ]);
    }
}
