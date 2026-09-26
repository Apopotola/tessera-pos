<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Organisation\Models\Till;
use Modules\Sales\Http\Resources\SaleResource;
use Modules\Sales\Models\ParkedSale;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleLine;
use Modules\Sales\Models\SaleTender;
use Modules\Sales\Services\ParkedSaleService;
use Modules\Sales\Services\SaleReturnService;
use Modules\Sales\Services\SaleService;
use Modules\Sales\Services\TillApprovalService;
use Modules\Sales\Services\TillCatalogueService;
use Modules\Sales\Services\TillPolicy;
use OpenApi\Attributes as OA;

/** Everything the selling screen calls. Requires a signed-in cashier on a paired till. */
class TillSaleController extends Controller
{
    public const RELATIONS = ['lines.variant.product', 'lines.approver', 'tenders.confirmation', 'returns.tenders', 'branch.business', 'till', 'cashier', 'etimsSubmission', 'customer'];

    public function __construct(
        private readonly TillCatalogueService $catalogue,
        private readonly SaleService $sales,
        private readonly SaleReturnService $returns,
        private readonly TillApprovalService $approvals,
        private readonly AuditLogger $audit,
        private readonly ParkedSaleService $parking,
        private readonly TillPolicy $policy,
    ) {}

    #[OA\Get(path: '/api/v1/sales/till/items', summary: 'Search sellable items with this branch price and shop-floor stock', tags: ['Till'], responses: [new OA\Response(response: 200, description: 'Items')])]
    public function items(Request $request): JsonResponse
    {
        $this->requireSeller($request);
        $term = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']])['search'];

        return $this->success('Items.', $this->catalogue->search($this->till($request), $term));
    }

    #[OA\Get(path: '/api/v1/sales/till/favourites', summary: 'Favourite items for the first screen (Settings → Sales screen)', tags: ['Till'], responses: [new OA\Response(response: 200, description: 'Items')])]
    public function favourites(Request $request): JsonResponse
    {
        $this->requireSeller($request);

        return $this->success('Favourites.', $this->catalogue->favourites($this->till($request)));
    }

    #[OA\Get(path: '/api/v1/sales/till/scan/{code}', summary: 'Resolve a scanned barcode (case barcodes return units per case)', tags: ['Till'], responses: [new OA\Response(response: 200, description: 'Item'), new OA\Response(response: 404, description: 'Unknown barcode')])]
    public function scan(Request $request, string $code): JsonResponse
    {
        $this->requireSeller($request);
        $result = $this->catalogue->scan($this->till($request), $code);

        return $result ? $this->success('Item.', $result) : $this->error('No item has this barcode.', 404);
    }

    #[OA\Get(path: '/api/v1/sales/till/approvers', summary: 'Managers who can approve at this till (display names only)', tags: ['Till'], responses: [new OA\Response(response: 200, description: 'Approvers')])]
    public function approvers(Request $request): JsonResponse
    {
        $this->requireSeller($request);
        $till = $this->till($request);
        $action = $request->validate(['action' => ['required', Rule::in(array_keys(TillApprovalService::ACTIONS))]])['action'];

        $approvers = User::permission(TillApprovalService::ACTIONS[$action])
            ->where('is_active', true)
            ->whereNotNull('pin_hash')
            ->whereKeyNot($request->user()->id)
            ->where(fn ($q) => $q->whereHas('branches', fn ($b) => $b->whereKey($till->branch_id))
                ->orWhere(fn ($all) => $all->permission(Permissions::ORGANISATION_ALL_BRANCHES)))
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]);

        return $this->success('Approvers.', $approvers);
    }

    #[OA\Post(path: '/api/v1/sales/till/approvals', summary: 'Manager PIN approval; returns a single-use token for one action', tags: ['Till'], responses: [new OA\Response(response: 200, description: 'Token'), new OA\Response(response: 422, description: 'Wrong PIN')])]
    public function approve(Request $request): JsonResponse
    {
        $this->requireSeller($request);
        $data = $request->validate([
            'approverId' => ['required', 'integer'],
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
            'action' => ['required', Rule::in(array_keys(TillApprovalService::ACTIONS))],
        ]);

        $grant = $this->approvals->grant($this->till($request), $request->user(), $data['approverId'], $data['pin'], $data['action']);

        return $this->success("Approved by {$grant['approver']['name']}.", $grant);
    }

    #[OA\Post(
        path: '/api/v1/sales/till/sales',
        summary: 'Complete a sale (idempotent on clientId). Prices come from the server price list.',
        tags: ['Till'],
        responses: [new OA\Response(response: 201, description: 'Sale'), new OA\Response(response: 200, description: 'Already recorded (replay)'), new OA\Response(response: 422, description: 'Validation / payment short')],
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'clientId' => ['required', 'uuid'],
            'customerPin' => ['nullable', 'string', 'regex:/^[A-Za-z]\d{9}[A-Za-z]$/'],
            'customerId' => ['nullable', 'integer'],
            // Offline till: when the sale actually happened (ISO 8601 with offset).
            'occurredAt' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variantId' => ['required', 'integer'],
            'lines.*.unit' => ['nullable', Rule::in([SaleLine::UNIT_BOTTLE, SaleLine::UNIT_TOT])],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'lines.*.unitPriceCents' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'lines.*.discountCents' => ['nullable', 'integer', 'min:0'],
            'lines.*.approvalToken' => ['nullable', 'string', 'max:60'],
            // Manager approval to sell more than the shop floor holds (Settings → stock.below_zero).
            'stockApprovalToken' => ['nullable', 'string', 'max:60'],
            // Manager approval for a sale on account over the limit (or any, per Settings).
            'creditApprovalToken' => ['nullable', 'string', 'max:60'],
            'tenders' => ['required', 'array', 'min:1', 'max:5'],
            'tenders.*.method' => ['required', Rule::in([SaleTender::CASH, SaleTender::MPESA, SaleTender::CARD, SaleTender::CREDIT])],
            'tenders.*.amountCents' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'tenders.*.reference' => ['nullable', 'string', 'alpha_num', 'max:30'],
            'tenders.*.confirmationId' => ['nullable', 'integer'],
            'tenders.*.cardLast4' => ['nullable', 'digits:4'],
        ]);

        ['sale' => $sale, 'replayed' => $replayed] = $this->sales->complete($this->till($request), $request->user(), $data);

        return $this->success($replayed ? 'Sale already recorded.' : "Sale {$sale->number} complete.", new SaleResource($sale->load(self::RELATIONS)), $replayed ? 200 : 201);
    }

    #[OA\Get(path: '/api/v1/sales/till/catalogue', summary: 'Everything the till needs to keep selling offline: items, prices, barcodes, stock, customers', tags: ['Till'], responses: [new OA\Response(response: 200, description: 'Snapshot')])]
    public function catalogue(Request $request): JsonResponse
    {
        $this->requireSeller($request);

        return $this->success('Till catalogue.', $this->catalogue->snapshot($this->till($request)));
    }

    #[OA\Get(path: '/api/v1/sales/till/sales/{number}', summary: 'Find a sale at this branch by receipt number (for reprints and returns)', tags: ['Till'], responses: [new OA\Response(response: 200, description: 'Sale')])]
    public function find(Request $request, string $number): JsonResponse
    {
        $this->requireSeller($request);
        $sale = Sale::query()->where('number', mb_strtoupper(trim($number)))->where('branch_id', $this->till($request)->branch_id)->first();

        return $sale ? $this->success('Sale.', new SaleResource($sale->load(self::RELATIONS))) : $this->error('No sale with this receipt number at this branch.', 404);
    }

    #[OA\Post(path: '/api/v1/sales/till/returns', summary: 'Customer return with manager approval token; refund in cash', tags: ['Till'], responses: [new OA\Response(response: 201, description: 'Return; data = updated sale')])]
    public function storeReturn(Request $request): JsonResponse
    {
        $this->requireSeller($request);
        $data = $request->validate([
            'saleId' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
            // Required when Settings → Approvals → Refund needs a manager (checked in the service).
            'approvalToken' => ['nullable', 'string', 'max:60'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.saleLineId' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.restock' => ['required', 'boolean'],
        ]);

        $return = $this->returns->create($this->till($request), $request->user(), $data);

        $toAccount = -(int) SaleTender::query()->where('sale_return_id', $return->id)->where('method', SaleTender::CREDIT)->sum('amount_cents');
        $message = "Return {$return->number}: refund ".number_format(($return->total_cents - $toAccount) / 100, 2).' in cash'
            .($toAccount ? ', '.number_format($toAccount / 100, 2).' back to the account.' : '.');

        return $this->success($message, new SaleResource($return->sale->load(self::RELATIONS)), 201);
    }

    #[OA\Post(path: '/api/v1/sales/till/voids', summary: 'Log a line removed from the cart (manager token needed above the threshold)', tags: ['Till'], responses: [new OA\Response(response: 200, description: 'Logged')])]
    public function void(Request $request): JsonResponse
    {
        $this->requireSeller($request);
        $data = $request->validate([
            'variantId' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'valueCents' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:200'],
            'approvalToken' => ['nullable', 'string', 'max:60'],
        ]);
        $till = $this->till($request);

        $threshold = $this->policy->voidApprovalThresholdCents($till);
        $approverId = $threshold !== null && $data['valueCents'] > $threshold
            ? $this->approvals->consume($data['approvalToken'] ?? null, 'void', $till, $request->user())
            : null;

        $this->audit->log('sales.line.voided', ProductVariant::query()->find($data['variantId']), after: [
            'quantity' => $data['quantity'],
            'value_cents' => $data['valueCents'],
        ], reason: $data['reason'] ?? null, userId: $request->user()->id, approverId: $approverId, branchId: $till->branch_id, reference: "till:{$till->id}");

        return $this->success('Line removed.');
    }

    #[OA\Get(path: '/api/v1/sales/till/parked', summary: 'Sales parked on this till', tags: ['Till'], responses: [new OA\Response(response: 200, description: 'Parked sales')])]
    public function parked(Request $request): JsonResponse
    {
        $this->requireSeller($request);

        return $this->success('Parked sales.', $this->parking->forTill($this->till($request))->map(fn (ParkedSale $p) => $this->parkedArray($p))->values());
    }

    #[OA\Post(path: '/api/v1/sales/till/parked', summary: 'Park the current cart to serve another customer', tags: ['Till'], responses: [new OA\Response(response: 201, description: 'Parked')])]
    public function park(Request $request): JsonResponse
    {
        $this->requireSeller($request);
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            'totalCents' => ['required', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variantId' => ['required', 'integer'],
            'lines.*.unit' => ['required', Rule::in([SaleLine::UNIT_BOTTLE, SaleLine::UNIT_TOT])],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'lines.*.displayName' => ['required', 'string', 'max:200'],
            'lines.*.sku' => ['required', 'string', 'max:40'],
            'lines.*.listPriceCents' => ['required', 'integer', 'min:0'],
            'lines.*.unitPriceCents' => ['required', 'integer', 'min:0'],
            'lines.*.discountCents' => ['required', 'integer', 'min:0'],
            'lines.*.totMl' => ['nullable', 'integer'],
        ]);

        $parked = $this->parking->park($this->till($request), $request->user(), $data);

        return $this->success("Sale parked as \"{$parked->label}\".", $this->parkedArray($parked->load('user')), 201);
    }

    #[OA\Post(path: '/api/v1/sales/till/parked/{parkedSale}/recall', summary: 'Recall a parked sale (removes it from the list)', tags: ['Till'], responses: [new OA\Response(response: 200, description: 'The parked cart')])]
    public function recall(Request $request, ParkedSale $parkedSale): JsonResponse
    {
        $this->requireSeller($request);
        $parked = $this->parking->recall($this->till($request), $request->user(), $parkedSale->load('user'));

        return $this->success('Sale recalled.', $this->parkedArray($parked));
    }

    /** @return array<string, mixed> */
    private function parkedArray(ParkedSale $parked): array
    {
        return [
            'id' => $parked->id,
            'label' => $parked->label,
            'lines' => $parked->lines,
            'totalCents' => $parked->total_cents,
            'parkedBy' => $parked->user?->name,
            'createdAt' => $parked->created_at?->toIso8601String(),
        ];
    }

    private function till(Request $request): Till
    {
        return $request->attributes->get('till');
    }

    private function requireSeller(Request $request): void
    {
        abort_unless($request->user()->can(Permissions::SALES_SELL), 403);
    }
}
