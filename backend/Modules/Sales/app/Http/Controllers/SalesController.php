<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Services\StockQueryService;
use Modules\Sales\Http\Resources\SaleResource;
use Modules\Sales\Http\Resources\ShiftResource;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleTender;
use Modules\Sales\Models\Shift;
use OpenApi\Attributes as OA;

/** Back-office views of sales and shifts. */
class SalesController extends Controller
{
    public function __construct(private readonly StockQueryService $stock) {}

    #[OA\Get(path: '/api/v1/sales/sales', summary: 'Sales, newest first (filter by date range, cashier, receipt number)', tags: ['Sales'], responses: [new OA\Response(response: 200, description: 'Paginated')])]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::SALES_VIEW), 403);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'userId' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = Sale::query()
            ->with(TillSaleController::RELATIONS)
            ->whereIn('branch_id', $this->stock->branchIds($request->user(), null))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('completed_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('completed_at', '<', now()->parse($d)->addDay()))
            ->when($filters['userId'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('number', 'like', '%'.mb_strtoupper($s).'%'))
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->paginate(25);

        return $this->success('Sales.', $this->paginated($page, SaleResource::class));
    }

    #[OA\Get(path: '/api/v1/sales/shifts', summary: 'Shifts with cash-up results (variance only after close)', tags: ['Sales'], responses: [new OA\Response(response: 200, description: 'Paginated')])]
    public function shifts(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny([Permissions::SHIFTS_CASHUP_APPROVE, Permissions::SALES_VIEW]), 403);

        $page = Shift::query()
            ->with(['user', 'till'])
            ->withCount('sales')
            ->withSum(['tenders as cash_cents' => fn ($q) => $q->where('method', SaleTender::CASH)], 'amount_cents')
            ->withSum(['tenders as mpesa_cents' => fn ($q) => $q->where('method', SaleTender::MPESA)], 'amount_cents')
            ->withSum(['tenders as card_cents' => fn ($q) => $q->where('method', SaleTender::CARD)], 'amount_cents')
            ->whereIn('branch_id', $this->stock->branchIds($request->user(), null))
            ->orderByDesc('opened_at')
            ->paginate(25);

        $page->setCollection($page->getCollection()->map(fn (Shift $s) => [
            ...(new ShiftResource($s))->toArray($request),
            'tillName' => $s->till->name,
            'salesCount' => $s->sales_count,
            'takings' => ['cashCents' => (int) $s->cash_cents, 'mpesaCents' => (int) $s->mpesa_cents, 'cardCents' => (int) $s->card_cents],
            'closeNote' => $s->close_note,
        ]));

        return $this->success('Shifts.', $this->paginated($page));
    }
}
