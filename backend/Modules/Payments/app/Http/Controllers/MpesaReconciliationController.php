<?php

namespace Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Services\StockQueryService;
use Modules\Payments\Http\Resources\ConfirmationPresenter;
use Modules\Payments\Models\MpesaConfirmation;
use Modules\Payments\Services\MpesaAllocator;
use Modules\Sales\Models\SaleTender;
use OpenApi\Attributes as OA;

/** Back office: every M-PESA confirmation matched to a sale, plus what still needs matching. */
class MpesaReconciliationController extends Controller
{
    public function __construct(
        private readonly MpesaAllocator $allocator,
        private readonly StockQueryService $stock,
    ) {}

    #[OA\Get(path: '/api/v1/payments/mpesa/confirmations', summary: 'M-PESA confirmations (matched / unallocated) for a period', tags: ['Payments'], responses: [new OA\Response(response: 200, description: 'Paginated + summary')])]
    public function confirmations(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PAYMENTS_VIEW), 403);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(['all', 'matched', 'unallocated'])],
            'search' => ['nullable', 'string', 'max:30'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $branchIds = $this->stock->branchIds($request->user(), null);

        $base = MpesaConfirmation::query()
            // Customer-initiated payments have no branch until matched; show them to everyone who reconciles.
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $branchIds))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('transacted_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('transacted_at', '<', now()->parse($d)->addDay()))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('receipt', 'like', '%'.mb_strtoupper($s).'%'));

        $summary = [
            'matchedCount' => (clone $base)->whereNotNull('tender_id')->count(),
            'matchedCents' => (int) (clone $base)->whereNotNull('tender_id')->sum('amount_cents'),
            'unallocatedCount' => (clone $base)->whereNull('tender_id')->count(),
            'unallocatedCents' => (int) (clone $base)->whereNull('tender_id')->sum('amount_cents'),
        ];

        $page = (clone $base)
            ->with(['tender.sale', 'allocator'])
            ->when(($filters['status'] ?? 'all') === 'matched', fn ($q) => $q->whereNotNull('tender_id'))
            ->when(($filters['status'] ?? 'all') === 'unallocated', fn ($q) => $q->whereNull('tender_id'))
            ->orderByDesc('transacted_at')
            ->paginate(25);

        $page->setCollection($page->getCollection()->map(fn (MpesaConfirmation $c) => ConfirmationPresenter::backOffice($c)));

        return $this->success('M-PESA confirmations.', [...$this->paginated($page), 'summary' => $summary]);
    }

    #[OA\Get(path: '/api/v1/payments/mpesa/unverified', summary: 'M-PESA tenders typed in by cashiers with no confirmation yet', tags: ['Payments'], responses: [new OA\Response(response: 200, description: 'Tenders')])]
    public function unverified(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PAYMENTS_VIEW), 403);
        $branchIds = $this->stock->branchIds($request->user(), null);

        $tenders = SaleTender::query()
            ->with(['sale', 'shift.user'])
            ->where('method', SaleTender::MPESA)
            ->where('status', SaleTender::UNVERIFIED)
            ->where('amount_cents', '>', 0)
            ->whereDoesntHave('confirmation')
            ->whereHas('shift', fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        // The code a cashier typed, where Safaricom has since confirmed it.
        $suggested = MpesaConfirmation::query()->unallocated()
            ->whereIn('receipt', $tenders->pluck('reference')->filter()->map(fn ($r) => mb_strtoupper($r))->all())
            ->pluck('id', 'receipt');

        $rows = $tenders->map(fn (SaleTender $t) => [
            'id' => $t->id,
            'amountCents' => $t->amount_cents,
            'reference' => $t->reference,
            'createdAt' => $t->created_at?->toIso8601String(),
            'sale' => $t->sale ? ['id' => $t->sale->id, 'number' => $t->sale->number] : null,
            'cashier' => $t->shift->user->name,
            'suggestedConfirmationId' => $suggested[mb_strtoupper((string) $t->reference)] ?? null,
        ])
            ->values();

        return $this->success('Unverified M-PESA payments.', $rows);
    }

    #[OA\Post(path: '/api/v1/payments/mpesa/confirmations/{confirmation}/match', summary: 'Match a confirmation to an unverified M-PESA tender', tags: ['Payments'], responses: [new OA\Response(response: 200, description: 'Matched')])]
    public function match(Request $request, MpesaConfirmation $confirmation): JsonResponse
    {
        $data = $request->validate(['tenderId' => ['required', 'integer']]);
        $tender = SaleTender::query()->with('shift')->findOrFail($data['tenderId']);
        abort_unless(in_array($tender->shift->branch_id, $this->stock->branchIds($request->user(), null), true), 403);

        $matched = $this->allocator->reconcile($confirmation, $tender, $request->user());

        return $this->success("{$matched->receipt} matched to the sale.", ConfirmationPresenter::backOffice($matched->load(['tender.sale', 'allocator'])));
    }
}
