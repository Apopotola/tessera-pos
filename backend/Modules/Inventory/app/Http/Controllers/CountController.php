<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Enums\CountStatus;
use Modules\Inventory\Http\Requests\InventoryRequests;
use Modules\Inventory\Http\Resources\CountResource;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Services\CountService;
use Modules\Inventory\Services\StockQueryService;
use OpenApi\Attributes as OA;

class CountController extends Controller
{
    private const RELATIONS = ['location', 'creator', 'submitter', 'reviewer', 'lines.variant.product'];

    public function __construct(
        private readonly CountService $counts,
        private readonly StockQueryService $stock,
    ) {}

    #[OA\Get(path: '/api/v1/inventory/counts', summary: 'Stock counts', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Paginated')])]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny([Permissions::INVENTORY_VIEW, Permissions::INVENTORY_COUNT]), 403);
        $filters = $request->validate(['status' => ['nullable', Rule::enum(CountStatus::class)], 'page' => ['nullable', 'integer', 'min:1']]);

        $page = StockCount::query()
            ->with(['location', 'creator', 'submitter', 'reviewer'])
            ->withCount('lines')
            ->whereIn('branch_id', $this->stock->branchIds($request->user(), null))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        // List rows omit lines; the sheet is fetched per count.
        $page->setCollection($page->getCollection()->each->setRelation('lines', collect()));

        return $this->success('Stock counts.', $this->paginated($page, CountResource::class));
    }

    #[OA\Get(path: '/api/v1/inventory/counts/{count}', summary: 'Count sheet (expected quantities hidden while counting)', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Count')])]
    public function show(Request $request, StockCount $count): JsonResponse
    {
        abort_unless($request->user()->canAny([Permissions::INVENTORY_VIEW, Permissions::INVENTORY_COUNT]), 403);
        abort_unless(in_array($count->branch_id, $this->stock->branchIds($request->user(), null), true), 404);

        return $this->respond('Stock count.', $count);
    }

    #[OA\Post(path: '/api/v1/inventory/counts', summary: 'Start a blind count at a location', tags: ['Inventory'], responses: [new OA\Response(response: 201, description: 'Started')])]
    public function store(Request $request): JsonResponse
    {
        $count = $this->counts->create($request->validate(InventoryRequests::count()), $request->user());

        return $this->respond("Count {$count->number} started.", $count, 201);
    }

    #[OA\Put(path: '/api/v1/inventory/counts/{count}/lines', summary: 'Save counted quantities (adds items found on the shelf)', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Saved')])]
    public function record(Request $request, StockCount $count): JsonResponse
    {
        $lines = $request->validate(InventoryRequests::countLines())['lines'];

        return $this->respond('Count saved.', $this->counts->record($count, $request->user(), $lines));
    }

    #[OA\Post(path: '/api/v1/inventory/counts/{count}/submit', summary: 'Submit; freezes expected quantities and reveals variances', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Submitted')])]
    public function submit(Request $request, StockCount $count): JsonResponse
    {
        return $this->respond('Count submitted for approval.', $this->counts->submit($count, $request->user()));
    }

    #[OA\Post(path: '/api/v1/inventory/counts/{count}/approve', summary: 'Approve and post variances (not the submitter)', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Approved')])]
    public function approve(Request $request, StockCount $count): JsonResponse
    {
        $note = $request->validate(InventoryRequests::review(false))['note'] ?? null;

        return $this->respond('Count approved; stock corrected.', $this->counts->approve($count, $request->user(), $note));
    }

    #[OA\Post(path: '/api/v1/inventory/counts/{count}/reject', summary: 'Reject (recount needed)', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Rejected')])]
    public function reject(Request $request, StockCount $count): JsonResponse
    {
        $note = $request->validate(InventoryRequests::review(true))['note'];

        return $this->respond('Count rejected.', $this->counts->reject($count, $request->user(), $note));
    }

    private function respond(string $message, StockCount $count, int $status = 200): JsonResponse
    {
        return $this->success($message, new CountResource($count->fresh(self::RELATIONS)), $status);
    }
}
