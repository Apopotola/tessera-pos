<?php

namespace Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Models\Promotion;
use Modules\Catalogue\Services\PromotionService;
use OpenApi\Attributes as OA;

/** Promotions: list, set up (for the owner's approval), approve / reject, end early. */
class PromotionController extends Controller
{
    public function __construct(private readonly PromotionService $promotions) {}

    #[OA\Get(path: '/api/v1/catalogue/promotions', summary: 'Promotions by status (pending, active, finished)', tags: ['Catalogue'], responses: [new OA\Response(response: 200, description: 'Paginated')])]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny([Permissions::PROMOTIONS_REQUEST, Permissions::PROMOTIONS_APPROVE]), 403);
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'active', 'finished'])], 'page' => ['nullable', 'integer', 'min:1']])['status'] ?? 'active';

        $query = Promotion::query()->with(['targets', 'requester', 'reviewer'])
            ->withSum('saleLines as discount_given_cents', 'promotion_discount_cents')
            ->withCount('saleLines as lines_count');
        match ($status) {
            'pending' => $query->where('status', Promotion::PENDING),
            'active' => $query->where('status', Promotion::ACTIVE)->whereDate('ends_on', '>=', now()),
            'finished' => $query->where(fn ($q) => $q->whereIn('status', [Promotion::ENDED, Promotion::REJECTED])
                ->orWhere(fn ($q) => $q->where('status', Promotion::ACTIVE)->whereDate('ends_on', '<', now()))),
        };
        $page = $query->orderByDesc('id')->paginate(25);
        $page->setCollection($page->getCollection()->map(fn (Promotion $p) => $this->promotionArray($p)));

        return $this->success('Promotions.', $this->paginated($page));
    }

    #[OA\Post(path: '/api/v1/catalogue/promotions', summary: 'Set up a promotion (the owner approves it; an owner\'s own is approved at once)', tags: ['Catalogue'], responses: [new OA\Response(response: 201, description: 'Promotion')])]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'discountType' => ['required', Rule::in(['percent', 'amount'])],
            // percent: basis points (1000 = 10%), at most 90%; amount: cents off each unit.
            'discountValue' => ['required', 'integer', 'min:1', $request->input('discountType') === 'percent' ? 'max:9000' : 'max:100000000'],
            'minQuantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'unit' => ['nullable', Rule::in(['bottle', 'tot', 'any'])],
            'startsOn' => ['required', 'date', 'after_or_equal:today'],
            'endsOn' => ['required', 'date', 'after_or_equal:startsOn'],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'timeFrom' => ['nullable', 'required_with:timeTo', 'date_format:H:i'],
            'timeTo' => ['nullable', 'required_with:timeFrom', 'date_format:H:i', 'different:timeFrom'],
            'branchIds' => ['nullable', 'array'],
            'branchIds.*' => ['integer', Rule::exists('branches', 'id')],
            'categoryIds' => ['nullable', 'array'],
            'categoryIds.*' => ['integer', Rule::exists('categories', 'id')],
            'brandIds' => ['nullable', 'array'],
            'brandIds.*' => ['integer', Rule::exists('brands', 'id')],
            'variantIds' => ['nullable', 'array'],
            'variantIds.*' => ['integer', Rule::exists('product_variants', 'id')],
        ]);
        $promotion = $this->promotions->request($data, $request->user());
        $message = $promotion->status === Promotion::ACTIVE ? 'Promotion approved and scheduled.' : 'Promotion sent to the owner for approval.';

        return $this->success($message, $this->promotionArray($promotion->load(['targets', 'requester', 'reviewer'])), 201);
    }

    #[OA\Post(path: '/api/v1/catalogue/promotions/{promotion}/approve', summary: 'Approve a promotion (not your own)', tags: ['Catalogue'], responses: [new OA\Response(response: 200, description: 'Promotion')])]
    public function approve(Request $request, Promotion $promotion): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->success('Promotion approved.', $this->promotionArray($this->promotions->approve($promotion, $request->user(), $note)->load(['targets', 'requester', 'reviewer'])));
    }

    #[OA\Post(path: '/api/v1/catalogue/promotions/{promotion}/reject', summary: 'Reject a promotion with a reason', tags: ['Catalogue'], responses: [new OA\Response(response: 200, description: 'Promotion')])]
    public function reject(Request $request, Promotion $promotion): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'max:500']])['note'];

        return $this->success('Promotion rejected.', $this->promotionArray($this->promotions->reject($promotion, $request->user(), $note)->load(['targets', 'requester', 'reviewer'])));
    }

    #[OA\Post(path: '/api/v1/catalogue/promotions/{promotion}/end', summary: 'End a running promotion now', tags: ['Catalogue'], responses: [new OA\Response(response: 200, description: 'Promotion')])]
    public function end(Request $request, Promotion $promotion): JsonResponse
    {
        $reason = $request->validate(['note' => ['required', 'string', 'max:500']])['note'];

        return $this->success('Promotion ended.', $this->promotionArray($this->promotions->end($promotion, $request->user(), $reason)->load(['targets', 'requester', 'reviewer'])));
    }

    /** @return array<string, mixed> */
    private function promotionArray(Promotion $p): array
    {
        $names = fn (string $table, string $type) => DB::table($table)->whereIn('id', $p->targets->where('target_type', $type)->pluck('target_id'))->pluck('name', 'id');
        $variants = DB::table('product_variants as v')->join('products as pr', 'pr.id', '=', 'v.product_id')
            ->whereIn('v.id', $p->targets->where('target_type', 'variant')->pluck('target_id'))
            ->get(['v.id', 'pr.name', 'v.volume_ml'])->mapWithKeys(fn ($v) => [$v->id => trim("{$v->name} ".($v->volume_ml ? "{$v->volume_ml}ml" : ''))]);

        return [
            ...$p->rule(),
            'status' => $p->status === Promotion::ACTIVE && $p->ends_on->lt(now()->startOfDay()) ? 'finished' : $p->status,
            'categories' => $names('categories', 'category')->values(),
            'brands' => $names('brands', 'brand')->values(),
            'variants' => $variants->values(),
            'requestedById' => $p->requested_by,
            'requestedBy' => $p->requester?->name,
            'reviewedBy' => $p->reviewer?->name,
            'reviewNote' => $p->review_note,
            'endedAt' => $p->ended_at?->toIso8601String(),
            'discountGivenCents' => (int) ($p->discount_given_cents ?? 0),
            'linesCount' => (int) ($p->lines_count ?? 0),
            'createdAt' => $p->created_at?->toIso8601String(),
        ];
    }
}
