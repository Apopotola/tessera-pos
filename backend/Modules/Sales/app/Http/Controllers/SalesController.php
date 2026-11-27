<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Services\StockQueryService;
use Modules\Sales\Http\Requests\ReviewCashCheckRequest;
use Modules\Sales\Http\Resources\CashCheckResource;
use Modules\Sales\Http\Resources\SaleResource;
use Modules\Sales\Http\Resources\ShiftResource;
use Modules\Sales\Models\CashCheck;
use Modules\Sales\Models\CashDrop;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleTender;
use Modules\Sales\Models\Shift;
use Modules\Sales\Services\ShiftService;
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

    #[OA\Get(path: '/api/v1/sales/shifts/{shift}/detail', summary: 'One cash-up: count by denomination, drops, reason, sign-off', tags: ['Sales'], responses: [new OA\Response(response: 200, description: 'Shift')])]
    public function shiftDetail(Request $request, Shift $shift): JsonResponse
    {
        abort_unless($request->user()->canAny([Permissions::SHIFTS_CASHUP_APPROVE, Permissions::SALES_VIEW]), 403);
        abort_unless(in_array($shift->branch_id, $this->stock->branchIds($request->user(), null), true), 403);
        $shift->load(['user', 'till', 'reviewer', 'drops.witness']);

        return $this->success('Shift.', [
            ...(new ShiftResource($shift))->toArray($request),
            'tillName' => $shift->till->name,
            'drops' => $shift->drops->map(fn (CashDrop $d) => [
                'id' => $d->id,
                'amountCents' => $d->amount_cents,
                'note' => $d->note,
                'witness' => $d->witness->name,
                'at' => $d->created_at->toIso8601String(),
            ])->values(),
        ]);
    }

    #[OA\Post(path: '/api/v1/sales/shifts/{shift}/review', summary: 'Manager signs off a closed cash-up', tags: ['Sales'], responses: [new OA\Response(response: 200, description: 'Signed off')])]
    public function reviewShift(Request $request, Shift $shift, ShiftService $shifts): JsonResponse
    {
        abort_unless(in_array($shift->branch_id, $this->stock->branchIds($request->user(), null), true), 403);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->success('Cash-up signed off.', new ShiftResource($shifts->review($shift, $request->user(), $note)->load(['user', 'reviewer'])));
    }

    #[OA\Get(path: '/api/v1/sales/cash-checks', summary: 'Cash checks on tills that stay open (status=to_review: not signed off yet)', tags: ['Sales'], responses: [new OA\Response(response: 200, description: 'Paginated')])]
    public function cashChecks(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny([Permissions::SHIFTS_CASHUP_APPROVE, Permissions::SALES_VIEW]), 403);

        $page = CashCheck::query()
            ->with(['user', 'till', 'reviewer'])
            ->whereIn('branch_id', $this->stock->branchIds($request->user(), null))
            ->when($request->query('status') === 'to_review', fn ($q) => $q->whereNull('reviewed_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return $this->success('Cash checks.', $this->paginated($page, CashCheckResource::class));
    }

    #[OA\Post(path: '/api/v1/sales/cash-checks/{cashCheck}/review', summary: 'Manager signs off a cash check (not the one who counted)', tags: ['Sales'], responses: [new OA\Response(response: 200, description: 'Signed off')])]
    public function reviewCashCheck(ReviewCashCheckRequest $request, CashCheck $cashCheck, ShiftService $shifts): JsonResponse
    {
        abort_unless(in_array($cashCheck->branch_id, $this->stock->branchIds($request->user(), null), true), 403);

        return $this->success('Cash check signed off.', new CashCheckResource($shifts->reviewCashCheck($cashCheck, $request->user(), $request->validated('note'))->load(['user', 'till', 'reviewer'])));
    }

    #[OA\Get(path: '/api/v1/sales/shifts', summary: 'Shifts with cash-up results (variance only after close)', tags: ['Sales'], responses: [new OA\Response(response: 200, description: 'Paginated')])]
    public function shifts(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny([Permissions::SHIFTS_CASHUP_APPROVE, Permissions::SALES_VIEW]), 403);

        $page = Shift::query()
            ->with(['user', 'till', 'reviewer'])
            ->when($request->query('status') === 'to_review', fn ($q) => $q->whereNotNull('closed_at')->whereNull('reviewed_at'))
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
