<?php

namespace Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Models\ExpenseCategory;
use Modules\Expenses\Services\ExpenseService;
use Modules\Organisation\Services\BranchAccessService;
use OpenApi\Attributes as OA;

/** Expenses: list and totals, record, approve / reject, reverse; cash paid out at the till. */
class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly BranchAccessService $branches,
    ) {}

    #[OA\Get(path: '/api/v1/expenses', summary: 'Expenses at my branches (filters: status, branch, dates) with totals', tags: ['Expenses'], responses: [new OA\Response(response: 200, description: 'Paginated + totals')])]
    public function index(Request $request): JsonResponse
    {
        $this->requireAccess($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([Expense::PENDING, Expense::APPROVED, Expense::REJECTED])],
            'branchId' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $branchIds = $this->branches->branchesFor($request->user())->modelKeys();
        $query = Expense::query()->whereIn('branch_id', isset($filters['branchId']) ? array_intersect($branchIds, [(int) $filters['branchId']]) : $branchIds)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('spent_on', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('spent_on', '<=', $d));

        $totals = [
            'approvedCents' => (int) (clone $query)->where('status', Expense::APPROVED)->sum('amount_cents'),
            'pendingCents' => (int) (clone $query)->where('status', Expense::PENDING)->sum('amount_cents'),
            'pendingCount' => (clone $query)->where('status', Expense::PENDING)->count(),
        ];
        $page = $query->with(['category', 'branch', 'requester', 'reviewer'])->orderByDesc('spent_on')->orderByDesc('id')->paginate(30);
        $reversed = Expense::query()->whereIn('reverses_id', $page->getCollection()->modelKeys())->pluck('reverses_id')->flip();
        $page->setCollection($page->getCollection()->map(fn (Expense $e) => $this->expenseArray($e, isset($reversed[$e->id]))));

        return $this->success('Expenses.', [...$this->paginated($page), 'totals' => $totals]);
    }

    #[OA\Get(path: '/api/v1/expenses/categories', summary: 'Expense categories', tags: ['Expenses'], responses: [new OA\Response(response: 200, description: 'Categories')])]
    public function categories(): JsonResponse
    {
        return $this->success('Categories.', ExpenseCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']));
    }

    #[OA\Post(path: '/api/v1/expenses', summary: 'Record an expense (a manager approves it)', tags: ['Expenses'], responses: [new OA\Response(response: 201, description: 'Expense')])]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branchId' => ['required', 'integer', Rule::exists('branches', 'id')],
            'categoryId' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('is_active', true)],
            'amountCents' => ['required', 'integer', 'min:100', 'max:100000000000'],
            'paidFrom' => ['required', Rule::in(['petty_cash', 'bank', 'mpesa'])],
            'payee' => ['nullable', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:60'],
            'spentOn' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $expense = $this->expenses->request($data, $request->user());

        return $this->success("Expense {$expense->number} sent for approval.", $this->expenseArray($expense->load(['category', 'branch', 'requester', 'reviewer']), false), 201);
    }

    #[OA\Post(path: '/api/v1/expenses/{expense}/approve', summary: 'Approve an expense (not your own)', tags: ['Expenses'], responses: [new OA\Response(response: 200, description: 'Expense')])]
    public function approve(Request $request, Expense $expense): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->success('Expense approved.', $this->expenseArray($this->expenses->approve($expense, $request->user(), $note)->load(['category', 'branch', 'requester', 'reviewer']), false));
    }

    #[OA\Post(path: '/api/v1/expenses/{expense}/reject', summary: 'Reject an expense with a reason', tags: ['Expenses'], responses: [new OA\Response(response: 200, description: 'Expense')])]
    public function reject(Request $request, Expense $expense): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'max:500']])['note'];

        return $this->success('Expense rejected.', $this->expenseArray($this->expenses->reject($expense, $request->user(), $note)->load(['category', 'branch', 'requester', 'reviewer']), false));
    }

    #[OA\Post(path: '/api/v1/expenses/{expense}/reverse', summary: 'Reverse an approved expense (a reversal row; nothing is edited)', tags: ['Expenses'], responses: [new OA\Response(response: 201, description: 'Reversal')])]
    public function reverse(Request $request, Expense $expense): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']])['note'];
        $reversal = $this->expenses->reverse($expense, $request->user(), $note);

        return $this->success("Expense {$expense->number} reversed.", $this->expenseArray($reversal->load(['category', 'branch', 'requester', 'reviewer']), false), 201);
    }

    #[OA\Post(path: '/api/v1/expenses/till/payouts', summary: 'Pay an expense out of the till drawer (manager PIN token)', tags: ['Till'], responses: [new OA\Response(response: 201, description: 'Expense')])]
    public function tillPayout(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::SALES_SELL), 403);
        $data = $request->validate([
            'categoryId' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('is_active', true)],
            'amountCents' => ['required', 'integer', 'min:100', 'max:100000000'],
            'payee' => ['nullable', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:255'],
            'approvalToken' => ['required', 'string', 'max:60'],
        ]);
        $expense = $this->expenses->tillPayout($request->attributes->get('till'), $request->user(), $data);

        return $this->success('Paid out KES '.number_format($expense->amount_cents / 100, 2).'.', $this->expenseArray($expense->load(['category', 'branch', 'requester', 'reviewer']), false), 201);
    }

    /** @return array<string, mixed> */
    private function expenseArray(Expense $e, bool $reversed): array
    {
        return [
            'id' => $e->id,
            'number' => $e->number,
            'branch' => ['id' => $e->branch->id, 'code' => $e->branch->code, 'name' => $e->branch->name],
            'category' => ['id' => $e->category->id, 'name' => $e->category->name],
            'amountCents' => $e->amount_cents,
            'paidFrom' => $e->paid_from,
            'payee' => $e->payee,
            'description' => $e->description,
            'reference' => $e->reference,
            'spentOn' => $e->spent_on->toDateString(),
            'status' => $e->status,
            'requestedById' => $e->requested_by,
            'requestedBy' => $e->requester?->name,
            'reviewedBy' => $e->reviewer?->name,
            'reviewNote' => $e->review_note,
            'isReversal' => $e->reverses_id !== null,
            'reversed' => $reversed,
        ];
    }

    private function requireAccess(Request $request): void
    {
        abort_unless($request->user()->canAny([Permissions::EXPENSES_REQUEST, Permissions::EXPENSES_APPROVE, Permissions::REPORTS_FINANCIAL_VIEW]), 403);
    }
}
