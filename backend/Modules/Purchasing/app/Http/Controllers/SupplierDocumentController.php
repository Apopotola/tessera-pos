<?php

namespace Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Enums\DocumentStatus;
use Modules\Inventory\Services\StockQueryService;
use Modules\Purchasing\Http\Resources\PurchasingResources;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Purchasing\Models\SupplierReturn;
use Modules\Purchasing\Services\SupplierInvoiceService;
use Modules\Purchasing\Services\SupplierReturnService;
use OpenApi\Attributes as OA;

/** Supplier invoices (three-way match) and returns to supplier. */
class SupplierDocumentController extends Controller
{
    private const RETURN_RELATIONS = ['supplier', 'location', 'requester', 'reviewer', 'lines.variant.product'];

    public function __construct(
        private readonly SupplierInvoiceService $invoices,
        private readonly SupplierReturnService $returns,
        private readonly StockQueryService $stock,
    ) {}

    #[OA\Get(path: '/api/v1/purchasing/invoices', summary: 'Supplier invoices (match=variance to list only mismatches)', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Paginated')])]
    public function invoices(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PURCHASING_VIEW), 403);
        $filters = $request->validate(['match' => ['nullable', Rule::in([SupplierInvoice::MATCHED, SupplierInvoice::VARIANCE])], 'page' => ['nullable', 'integer', 'min:1']]);

        $page = SupplierInvoice::query()
            ->with(['supplier', 'recorder', 'receipts'])
            ->when($filters['match'] ?? null, fn ($q, $m) => $q->where('match_status', $m))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(25);
        $page->setCollection($page->getCollection()->map(PurchasingResources::invoice(...)));

        return $this->success('Supplier invoices.', $this->paginated($page));
    }

    #[OA\Post(path: '/api/v1/purchasing/invoices', summary: 'Record a supplier invoice against goods received', tags: ['Purchasing'], responses: [new OA\Response(response: 201, description: 'Recorded; matchStatus shows the three-way match')])]
    public function storeInvoice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplierId' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'invoiceNumber' => ['required', 'string', 'max:60', Rule::unique('supplier_invoices', 'invoice_number')->where('supplier_id', $request->integer('supplierId'))],
            'invoiceDate' => ['required', 'date', 'before_or_equal:today'],
            'subtotalCents' => ['required', 'integer', 'min:0'],
            'vatCents' => ['required', 'integer', 'min:0'],
            'totalCents' => ['required', 'integer', 'min:1'],
            'grnIds' => ['required', 'array', 'min:1'],
            'grnIds.*' => ['integer', 'distinct', Rule::exists('goods_received_notes', 'id')],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $invoice = $this->invoices->record($data, $request->user());
        $message = $invoice->match_status === SupplierInvoice::MATCHED ? 'Invoice matches the goods received.' : 'Invoice recorded — it does not match the goods received.';

        return $this->success($message, PurchasingResources::invoice($invoice->load(['supplier', 'recorder', 'receipts'])), 201);
    }

    #[OA\Get(path: '/api/v1/purchasing/returns', summary: 'Returns to supplier', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Paginated')])]
    public function returns(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PURCHASING_VIEW), 403);
        $filters = $request->validate(['status' => ['nullable', Rule::enum(DocumentStatus::class)], 'page' => ['nullable', 'integer', 'min:1']]);

        $page = SupplierReturn::query()
            ->with(self::RETURN_RELATIONS)
            ->whereIn('branch_id', $this->stock->branchIds($request->user(), null))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);
        $page->setCollection($page->getCollection()->map(PurchasingResources::supplierReturn(...)));

        return $this->success('Returns to supplier.', $this->paginated($page));
    }

    #[OA\Post(path: '/api/v1/purchasing/returns', summary: 'Raise a return to supplier (pending approval)', tags: ['Purchasing'], responses: [new OA\Response(response: 201, description: 'Created')])]
    public function storeReturn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplierId' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'locationId' => ['required', 'integer', Rule::exists('locations', 'id')],
            'reason' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variantId' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        $return = $this->returns->create($data, $request->user());

        return $this->success("{$return->number} sent for approval.", PurchasingResources::supplierReturn($return->load(self::RETURN_RELATIONS)), 201);
    }

    #[OA\Post(path: '/api/v1/purchasing/returns/{supplierReturn}/approve', summary: 'Approve: stock leaves (not your own)', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Approved')])]
    public function approveReturn(Request $request, SupplierReturn $supplierReturn): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $return = $this->returns->approve($supplierReturn, $request->user(), $note);

        return $this->success("{$return->number} approved — stock updated.", PurchasingResources::supplierReturn($return->load(self::RETURN_RELATIONS)));
    }

    #[OA\Post(path: '/api/v1/purchasing/returns/{supplierReturn}/reject', summary: 'Reject with a reason', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Rejected')])]
    public function rejectReturn(Request $request, SupplierReturn $supplierReturn): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'max:500']])['note'];
        $return = $this->returns->reject($supplierReturn, $request->user(), $note);

        return $this->success("{$return->number} rejected.", PurchasingResources::supplierReturn($return->load(self::RETURN_RELATIONS)));
    }

    #[OA\Post(path: '/api/v1/purchasing/returns/{supplierReturn}/credit-note', summary: 'Record the supplier credit note reference', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Saved')])]
    public function creditNote(Request $request, SupplierReturn $supplierReturn): JsonResponse
    {
        $reference = $request->validate(['reference' => ['required', 'string', 'max:60']])['reference'];
        $return = $this->returns->recordCreditNote($supplierReturn, $request->user(), $reference);

        return $this->success('Credit note recorded.', PurchasingResources::supplierReturn($return->load(self::RETURN_RELATIONS)));
    }
}
