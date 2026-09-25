<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Http\Requests\InventoryRequests;
use Modules\Inventory\Http\Resources\TransferResource;
use Modules\Inventory\Models\StockTransfer;
use Modules\Inventory\Services\StockQueryService;
use Modules\Inventory\Services\TransferService;
use OpenApi\Attributes as OA;

class TransferController extends Controller
{
    private const RELATIONS = ['fromBranch', 'toBranch', 'fromLocation', 'toLocation', 'requester', 'approver', 'dispatcher', 'receiver', 'lines.variant.product'];

    public function __construct(
        private readonly TransferService $transfers,
        private readonly StockQueryService $stock,
    ) {}

    #[OA\Get(path: '/api/v1/inventory/transfers', summary: 'Transfers into or out of your branches', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Paginated')])]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::INVENTORY_VIEW), 403);
        // "open" = everything still moving: requested, approved or in transit.
        $filters = $request->validate(['status' => ['nullable', Rule::in(['open', ...array_column(TransferStatus::cases(), 'value')])], 'page' => ['nullable', 'integer', 'min:1']]);
        $branchIds = $this->stock->branchIds($request->user(), null);

        $page = StockTransfer::query()
            ->with(self::RELATIONS)
            ->where(fn ($q) => $q->whereIn('from_branch_id', $branchIds)->orWhereIn('to_branch_id', $branchIds))
            ->when($filters['status'] ?? null, fn ($q, $s) => $s === 'open'
                ? $q->whereIn('status', [TransferStatus::Requested, TransferStatus::Approved, TransferStatus::InTransit])
                : $q->where('status', $s))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return $this->success('Transfers.', $this->paginated($page, TransferResource::class));
    }

    #[OA\Post(path: '/api/v1/inventory/transfers', summary: 'Request a transfer', tags: ['Inventory'], responses: [new OA\Response(response: 201, description: 'Requested')])]
    public function store(Request $request): JsonResponse
    {
        $transfer = $this->transfers->request($request->validate(InventoryRequests::transfer()), $request->user());

        return $this->respond("Transfer {$transfer->number} requested.", $transfer, 201);
    }

    #[OA\Post(path: '/api/v1/inventory/transfers/{transfer}/approve', summary: 'Approve (source branch, not your own request)', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Approved')])]
    public function approve(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->respond('Transfer approved.', $this->transfers->approve($transfer, $request->user()));
    }

    #[OA\Post(path: '/api/v1/inventory/transfers/{transfer}/dispatch', summary: 'Dispatch: quantities {lineId: qty}; stock moves to in-transit', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'In transit')])]
    public function dispatch(Request $request, StockTransfer $transfer): JsonResponse
    {
        $data = $request->validate(InventoryRequests::lineQuantities());

        return $this->respond('Transfer dispatched.', $this->transfers->dispatch($transfer, $request->user(), $this->quantities($data)));
    }

    #[OA\Post(path: '/api/v1/inventory/transfers/{transfer}/receive', summary: 'Receive: quantities {lineId: qty}; shortfall becomes a pending transit breakage', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Received')])]
    public function receive(Request $request, StockTransfer $transfer): JsonResponse
    {
        $data = $request->validate(InventoryRequests::lineQuantities());

        return $this->respond('Transfer received.', $this->transfers->receive($transfer, $request->user(), $this->quantities($data), $data['note'] ?? null));
    }

    #[OA\Post(path: '/api/v1/inventory/transfers/{transfer}/cancel', summary: 'Cancel before dispatch', tags: ['Inventory'], responses: [new OA\Response(response: 200, description: 'Cancelled')])]
    public function cancel(Request $request, StockTransfer $transfer): JsonResponse
    {
        $reason = $request->validate(InventoryRequests::review(true))['note'];

        return $this->respond('Transfer cancelled.', $this->transfers->cancel($transfer, $request->user(), $reason));
    }

    /** @return array<int, int> */
    private function quantities(array $data): array
    {
        return collect($data['quantities'] ?? [])->mapWithKeys(fn ($qty, $lineId) => [(int) $lineId => (int) $qty])->all();
    }

    private function respond(string $message, StockTransfer $transfer, int $status = 200): JsonResponse
    {
        return $this->success($message, new TransferResource($transfer->load(self::RELATIONS)), $status);
    }
}
