<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Enums\DocumentStatus;
use Modules\Inventory\Http\Requests\InventoryRequests;
use Modules\Inventory\Http\Resources\AdjustmentResource;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Services\AdjustmentService;
use Modules\Inventory\Services\StockQueryService;
use OpenApi\Attributes as OA;

class AdjustmentController extends Controller
{
    private const RELATIONS = ['location', 'requester', 'reviewer', 'lines.variant.product'];

    public function __construct(
        private readonly AdjustmentService $adjustments,
        private readonly StockQueryService $stock,
    ) {}

    #[OA\Get(path: '/api/v1/inventory/adjustments', summary: 'Adjustments (breakage, losses, opening stock) by status', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Paginated')])]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::INVENTORY_VIEW), 403);
        $filters = $request->validate(['status' => ['nullable', Rule::enum(DocumentStatus::class)], 'page' => ['nullable', 'integer', 'min:1']]);

        $page = StockAdjustment::query()
            ->with(self::RELATIONS)
            ->whereIn('branch_id', $this->stock->branchIds($request->user(), null))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return $this->success('Adjustments.', $this->paginated($page, AdjustmentResource::class));
    }

    #[OA\Post(path: '/api/v1/inventory/adjustments', summary: 'Report a breakage/loss or record found/opening stock (pending approval)', tags: ['Inventory'], responses: [new OA\Response(response: 201, description: 'Created')])]
    public function store(Request $request): JsonResponse
    {
        $adjustment = $this->adjustments->create($request->validate(InventoryRequests::adjustment()), $request->user());

        return $this->success("Adjustment {$adjustment->number} sent for approval.", new AdjustmentResource($adjustment->load(self::RELATIONS)), 201);
    }

    #[OA\Post(path: '/api/v1/inventory/adjustments/{adjustment}/approve', summary: 'Approve and post to the ledger (not your own)', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Approved')])]
    public function approve(Request $request, StockAdjustment $adjustment): JsonResponse
    {
        $note = $request->validate(InventoryRequests::review(false))['note'] ?? null;

        return $this->success('Adjustment approved.', new AdjustmentResource($this->adjustments->approve($adjustment, $request->user(), $note)->load(self::RELATIONS)));
    }

    #[OA\Post(path: '/api/v1/inventory/adjustments/{adjustment}/reject', summary: 'Reject with a note', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Rejected')])]
    public function reject(Request $request, StockAdjustment $adjustment): JsonResponse
    {
        $note = $request->validate(InventoryRequests::review(true))['note'];

        return $this->success('Adjustment rejected.', new AdjustmentResource($this->adjustments->reject($adjustment, $request->user(), $note)->load(self::RELATIONS)));
    }
}
