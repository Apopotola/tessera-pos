<?php

namespace Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Aging;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Models\SupplierPayment;
use Modules\Purchasing\Services\SupplierAccountService;
use OpenApi\Attributes as OA;

/** Supplier balances (payables), aging, statements and payments made. */
class SupplierAccountController extends Controller
{
    public function __construct(private readonly SupplierAccountService $accounts) {}

    #[OA\Get(path: '/api/v1/purchasing/payables', summary: 'What we owe each supplier, aged', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: '{items, totals}')])]
    public function index(Request $request): JsonResponse
    {
        $this->requireAccess($request);
        $asAt = CarbonImmutable::parse($request->validate(['asAt' => ['nullable', 'date']])['asAt'] ?? now())->endOfDay();

        $balances = $this->accounts->balances();
        $items = Supplier::query()->whereIn('id', array_keys($balances))->orderBy('name')->get()
            ->map(fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name, ...$this->accounts->account($s, $asAt)])
            ->filter(fn ($row) => $row['balanceCents'] !== 0)->values();
        $totals = [
            'balanceCents' => $items->sum('balanceCents'),
            'dueCents' => $items->sum('dueCents'),
            'onQueryCents' => $items->sum('onQueryCents'),
            'aging' => collect(array_keys(Aging::BUCKETS))->mapWithKeys(fn ($b) => [$b => $items->sum("aging.{$b}")])->all(),
        ];

        return $this->success('Supplier balances.', ['asAt' => $asAt->toDateString(), 'items' => $items, 'totals' => $totals]);
    }

    #[OA\Get(path: '/api/v1/purchasing/suppliers/{supplier}/account', summary: 'Balance, amount due and aging for one supplier', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Account')])]
    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        $this->requireAccess($request);

        return $this->success('Account.', $this->accounts->account($supplier));
    }

    #[OA\Get(path: '/api/v1/purchasing/suppliers/{supplier}/statement', summary: 'Supplier statement for a period', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Statement')])]
    public function statement(Request $request, Supplier $supplier): JsonResponse
    {
        $this->requireAccess($request);
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return $this->success('Statement.', $this->accounts->statement($supplier, CarbonImmutable::parse($data['from'])->startOfDay(), CarbonImmutable::parse($data['to'])->endOfDay()));
    }

    #[OA\Get(path: '/api/v1/purchasing/suppliers/{supplier}/payments', summary: 'Payments made to the supplier (newest first)', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Payments')])]
    public function payments(Request $request, Supplier $supplier): JsonResponse
    {
        $this->requireAccess($request);
        $payments = SupplierPayment::query()->with('recorder')->where('supplier_id', $supplier->id)->orderByDesc('id')->limit(100)->get();
        $reversed = SupplierPayment::query()->whereIn('reverses_id', $payments->modelKeys())->pluck('reverses_id')->flip();

        return $this->success('Payments.', $payments->map(fn (SupplierPayment $p) => $this->paymentArray($p, isset($reversed[$p->id])))->values());
    }

    #[OA\Post(path: '/api/v1/purchasing/suppliers/{supplier}/payments', summary: 'Record a payment to the supplier (partial payments allowed)', tags: ['Purchasing'], responses: [new OA\Response(response: 201, description: 'Payment')])]
    public function store(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'amountCents' => ['required', 'integer', 'min:100', 'max:100000000000'],
            'method' => ['required', Rule::in(array_keys(SupplierPayment::METHODS))],
            'reference' => [Rule::requiredIf(fn () => $request->input('method') !== 'cash'), 'nullable', 'string', 'max:60'],
            'paidOn' => ['nullable', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $payment = $this->accounts->recordPayment($supplier, $data, $request->user());

        return $this->success("Payment {$payment->number} recorded.", $this->paymentArray($payment->load('recorder'), false), 201);
    }

    #[OA\Post(path: '/api/v1/purchasing/payments/{payment}/reverse', summary: 'Reverse a supplier payment recorded by mistake', tags: ['Purchasing'], responses: [new OA\Response(response: 201, description: 'Reversal')])]
    public function reverse(Request $request, SupplierPayment $payment): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];
        $reversal = $this->accounts->reversePayment($payment, $reason, $request->user());

        return $this->success("Payment {$payment->number} reversed.", $this->paymentArray($reversal->load('recorder'), false), 201);
    }

    /** @return array<string, mixed> */
    private function paymentArray(SupplierPayment $p, bool $reversed): array
    {
        return [
            'id' => $p->id,
            'number' => $p->number,
            'amountCents' => $p->amount_cents,
            'method' => $p->method,
            'reference' => $p->reference,
            'paidOn' => $p->paid_on->toDateString(),
            'note' => $p->note,
            'isReversal' => $p->reverses_id !== null,
            'reversed' => $reversed,
            'recordedBy' => $p->recorder?->name,
        ];
    }

    private function requireAccess(Request $request): void
    {
        abort_unless($request->user()->canAny([Permissions::PURCHASING_PAY, Permissions::REPORTS_FINANCIAL_VIEW]), 403);
    }
}
