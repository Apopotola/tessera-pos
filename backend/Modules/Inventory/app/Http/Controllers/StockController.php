<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Http\Resources\MovementResource;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\ReorderService;
use Modules\Inventory\Services\StockQueryService;
use Modules\Organisation\Enums\LocationType;
use Modules\Organisation\Models\Location;
use OpenApi\Attributes as OA;

class StockController extends Controller
{
    public function __construct(
        private readonly StockQueryService $stock,
        private readonly ReorderService $reorder,
    ) {}

    #[OA\Get(path: '/api/v1/inventory/stock', summary: 'Stock on hand per item per branch (zero stock included); cost/value only with reports.profit.view', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Paginated rows')])]
    public function onHand(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::INVENTORY_VIEW), 403);

        $filters = $request->validate([
            'branchId' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'categoryId' => ['nullable', 'integer'],
            'lowOnly' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->success('Stock on hand.', $this->paginated($this->stock->onHand($request->user(), $filters)));
    }

    #[OA\Get(path: '/api/v1/inventory/movements', summary: 'Stock ledger (newest first)', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Paginated movements')])]
    public function movements(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::INVENTORY_VIEW), 403);

        $filters = $request->validate([
            'branchId' => ['nullable', 'integer'],
            'variantId' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(MovementType::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = StockMovement::query()
            ->with(['branch', 'location', 'variant.product', 'user', 'approver'])
            ->whereIn('branch_id', $this->stock->branchIds($request->user(), $filters['branchId'] ?? null))
            ->when($filters['variantId'] ?? null, fn ($q, $id) => $q->where('variant_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('movement_type', $type))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('occurred_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('occurred_at', '<', now()->parse($d)->addDay()))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(50);

        return $this->success('Stock movements.', $this->paginated($page, MovementResource::class));
    }

    #[OA\Get(path: '/api/v1/inventory/locations', summary: 'Stock locations at branches the user can access (transit excluded)', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Locations')])]
    public function locations(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny([Permissions::INVENTORY_VIEW, Permissions::INVENTORY_BREAKAGE_REPORT]), 403);

        $locations = Location::query()
            ->with('branch:id,code,name')
            ->whereIn('branch_id', $this->stock->branchIds($request->user(), null))
            ->where('is_active', true)
            ->where('type', '!=', LocationType::Transit)
            ->orderBy('branch_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Location $l) => [
                'id' => $l->id,
                'branchId' => $l->branch_id,
                'branchCode' => $l->branch->code,
                'name' => $l->name,
                'type' => $l->type->value,
            ]);

        return $this->success('Locations.', $locations);
    }

    #[OA\Put(path: '/api/v1/inventory/reorder-levels', summary: 'Set or clear a branch reorder level for an item', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Saved')])]
    public function setReorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branchId' => ['required', 'integer', Rule::exists('branches', 'id')],
            'variantId' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            'reorderLevel' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'reorderQuantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        $this->reorder->set($request->user(), $data['branchId'], $data['variantId'], $data['reorderLevel'] ?? null, $data['reorderQuantity'] ?? null);

        return $this->success('Reorder level saved.');
    }
}
