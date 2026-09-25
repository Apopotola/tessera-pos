<?php

namespace Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Services\StockQueryService;
use Modules\Purchasing\Enums\PurchaseOrderStatus;
use Modules\Purchasing\Http\Resources\PurchasingResources;
use Modules\Purchasing\Models\GoodsReceivedNote;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Services\PurchaseOrderService;
use Modules\Purchasing\Services\ReceivingService;
use OpenApi\Attributes as OA;

class PurchaseOrderController extends Controller
{
    private const RELATIONS = ['supplier', 'location', 'creator', 'approver', 'lines.variant.product'];

    public function __construct(
        private readonly PurchaseOrderService $orders,
        private readonly ReceivingService $receiving,
        private readonly StockQueryService $stock,
    ) {}

    #[OA\Get(path: '/api/v1/purchasing/orders', summary: 'Purchase orders (status=open for anything not closed)', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Paginated')])]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PURCHASING_VIEW), 403);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', ...array_column(PurchaseOrderStatus::cases(), 'value')])],
            'supplierId' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = PurchaseOrder::query()
            ->with(self::RELATIONS)
            ->whereIn('branch_id', $this->stock->branchIds($request->user(), null))
            ->when($filters['supplierId'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $s === 'open'
                ? $q->whereIn('status', [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartiallyReceived])
                : $q->where('status', $s))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        $page->setCollection($page->getCollection()->map(PurchasingResources::order(...)));

        return $this->success('Purchase orders.', $this->paginated($page));
    }

    #[OA\Get(path: '/api/v1/purchasing/orders/{order}', summary: 'Purchase order with lines and goods received', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Order')])]
    public function show(Request $request, PurchaseOrder $order): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PURCHASING_VIEW), 403);
        abort_unless(in_array($order->branch_id, $this->stock->branchIds($request->user(), null), true), 404);

        return $this->respond('Purchase order.', $order);
    }

    #[OA\Post(path: '/api/v1/purchasing/orders', summary: 'Raise a purchase order (draft)', tags: ['Purchasing'], responses: [new OA\Response(response: 201, description: 'Draft created')])]
    public function store(Request $request): JsonResponse
    {
        $order = $this->orders->create($request->validate($this->rules()), $request->user());

        return $this->respond("{$order->number} created as a draft.", $order, 201);
    }

    #[OA\Put(path: '/api/v1/purchasing/orders/{order}', summary: 'Edit a draft purchase order', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Updated')])]
    public function update(Request $request, PurchaseOrder $order): JsonResponse
    {
        return $this->respond('Draft updated.', $this->orders->update($order, $request->validate($this->rules()), $request->user()));
    }

    #[OA\Post(path: '/api/v1/purchasing/orders/{order}/approve', summary: 'Approve (not your own)', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Approved')])]
    public function approve(Request $request, PurchaseOrder $order): JsonResponse
    {
        return $this->respond("{$order->number} approved.", $this->orders->approve($order, $request->user()));
    }

    #[OA\Post(path: '/api/v1/purchasing/orders/{order}/send', summary: 'Mark as sent to the supplier', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Sent')])]
    public function send(Request $request, PurchaseOrder $order): JsonResponse
    {
        return $this->respond("{$order->number} marked as sent.", $this->orders->markSent($order, $request->user()));
    }

    #[OA\Post(path: '/api/v1/purchasing/orders/{order}/cancel', summary: 'Cancel before any goods are received', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Cancelled')])]
    public function cancel(Request $request, PurchaseOrder $order): JsonResponse
    {
        $reason = $request->validate(['note' => ['required', 'string', 'max:500']])['note'];

        return $this->respond("{$order->number} cancelled.", $this->orders->cancel($order, $request->user(), $reason));
    }

    #[OA\Post(
        path: '/api/v1/purchasing/orders/{order}/receive',
        summary: 'Goods received note: good units post to stock at PO cost; damaged-on-arrival recorded only',
        tags: ['Purchasing'],
        responses: [new OA\Response(response: 201, description: 'GRN created; data = order')],
    )]
    public function receive(Request $request, PurchaseOrder $order): JsonResponse
    {
        $data = $request->validate([
            'deliveryNoteRef' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.lineId' => ['required', 'integer', 'distinct'],
            'lines.*.received' => ['required', 'integer', 'min:0', 'max:1000000'],
            'lines.*.damaged' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'lines.*.batchNumber' => ['nullable', 'string', 'max:60'],
            'lines.*.expiryDate' => ['nullable', 'date'],
        ]);

        $grn = $this->receiving->receive($order, $request->user(), $data['lines'], $data['deliveryNoteRef'] ?? null, $data['note'] ?? null);

        return $this->respond("{$grn->number} recorded — stock updated.", $order, 201);
    }

    #[OA\Get(path: '/api/v1/purchasing/receipts', summary: 'Goods received notes (uninvoiced=1 for invoice matching)', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'GRNs')])]
    public function receipts(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PURCHASING_VIEW), 403);
        $filters = $request->validate(['supplierId' => ['nullable', 'integer'], 'uninvoiced' => ['nullable', 'boolean']]);

        $grns = GoodsReceivedNote::query()
            ->with(['purchaseOrder', 'receiver', 'lines.variant.product'])
            ->whereIn('branch_id', $this->stock->branchIds($request->user(), null))
            ->when($filters['supplierId'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when(! empty($filters['uninvoiced']), fn ($q) => $q->whereNull('supplier_invoice_id'))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return $this->success('Goods received.', $grns->map(PurchasingResources::receipt(...)));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'supplierId' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'locationId' => ['required', 'integer', Rule::exists('locations', 'id')],
            'expectedDate' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variantId' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unitCostCents' => ['required', 'integer', 'min:1', 'max:100000000'],
        ];
    }

    private function respond(string $message, PurchaseOrder $order, int $status = 200): JsonResponse
    {
        $order = $order->fresh([...self::RELATIONS, 'receipts.receiver', 'receipts.lines.variant.product']);

        return $this->success($message, PurchasingResources::order($order), $status);
    }
}
