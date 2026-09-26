<?php

namespace Modules\Customers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Aging;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Customers\Models\Customer;
use Modules\Customers\Models\CustomerPayment;
use Modules\Customers\Services\CustomerAccountService;
use OpenApi\Attributes as OA;

/** Customer credit accounts: limits, balances and aging, statements, payments received. */
class CustomerAccountController extends Controller
{
    public function __construct(private readonly CustomerAccountService $accounts) {}

    #[OA\Get(path: '/api/v1/customers/accounts', summary: 'Customers with a credit account or a balance: what each owes, aged', tags: ['Customers'], responses: [new OA\Response(response: 200, description: '{items, totals}')])]
    public function index(Request $request): JsonResponse
    {
        $this->requireAccountsAccess($request);
        $asAt = CarbonImmutable::parse($request->validate(['asAt' => ['nullable', 'date']])['asAt'] ?? now())->endOfDay();

        $balances = $this->accounts->balances();
        $customers = Customer::query()->where(fn ($q) => $q->whereNotNull('credit_limit_cents')->orWhereIn('id', array_keys(array_filter($balances))))
            ->orderBy('name')->get();

        $items = $customers->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->name, 'kraPin' => $c->kra_pin, ...$this->accounts->account($c, $asAt)])->values();
        $totals = [
            'balanceCents' => $items->sum('balanceCents'),
            'overdueCents' => $items->sum('overdueCents'),
            'aging' => collect(array_keys(Aging::BUCKETS))->mapWithKeys(fn ($b) => [$b => $items->sum("aging.{$b}")])->all(),
        ];

        return $this->success('Customer accounts.', ['asAt' => $asAt->toDateString(), 'items' => $items, 'totals' => $totals]);
    }

    #[OA\Get(path: '/api/v1/customers/{customer}/account', summary: 'Credit limit, balance, available credit and aging', tags: ['Customers'], responses: [new OA\Response(response: 200, description: 'Account')])]
    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->requireAccountsAccess($request);

        return $this->success('Account.', $this->accounts->account($customer));
    }

    #[OA\Put(path: '/api/v1/customers/{customer}/credit', summary: 'Open, change or close a credit account (limit null = no account)', tags: ['Customers'], responses: [new OA\Response(response: 200, description: 'Account')])]
    public function updateCredit(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'creditLimitCents' => ['present', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'creditTermsDays' => ['required', 'integer', 'min:0', 'max:365'],
        ]);
        $this->accounts->setCredit($customer, $data['creditLimitCents'], (int) $data['creditTermsDays'], $request->user());

        return $this->success('Credit account saved.', $this->accounts->account($customer->fresh()));
    }

    #[OA\Get(path: '/api/v1/customers/{customer}/statement', summary: 'Statement for a period with opening and closing balance', tags: ['Customers'], responses: [new OA\Response(response: 200, description: 'Statement')])]
    public function statement(Request $request, Customer $customer): JsonResponse
    {
        $this->requireAccountsAccess($request);
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return $this->success('Statement.', $this->accounts->statement($customer, CarbonImmutable::parse($data['from'])->startOfDay(), CarbonImmutable::parse($data['to'])->endOfDay()));
    }

    #[OA\Get(path: '/api/v1/customers/{customer}/payments', summary: 'Payments received on the account (newest first)', tags: ['Customers'], responses: [new OA\Response(response: 200, description: 'Payments')])]
    public function payments(Request $request, Customer $customer): JsonResponse
    {
        $this->requireAccountsAccess($request);
        $payments = CustomerPayment::query()->with('recorder')->where('customer_id', $customer->id)->orderByDesc('id')->limit(100)->get();
        $reversed = CustomerPayment::query()->whereIn('reverses_id', $payments->modelKeys())->pluck('reverses_id')->flip();

        return $this->success('Payments.', $payments->map(fn (CustomerPayment $p) => $this->paymentArray($p, isset($reversed[$p->id])))->values());
    }

    #[OA\Post(path: '/api/v1/customers/{customer}/payments', summary: 'Receive a payment on the account', tags: ['Customers'], responses: [new OA\Response(response: 201, description: 'Payment')])]
    public function receive(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'amountCents' => ['required', 'integer', 'min:100', 'max:100000000000'],
            'method' => ['required', Rule::in(array_keys(CustomerPayment::METHODS))],
            'reference' => [Rule::requiredIf(fn () => $request->input('method') !== 'cash'), 'nullable', 'string', 'max:60'],
            'branchId' => ['required', 'integer'],
            'receivedAt' => ['nullable', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $payment = $this->accounts->receivePayment($customer, $data, $request->user());

        return $this->success("Payment {$payment->number} recorded.", $this->paymentArray($payment->load('recorder'), false), 201);
    }

    #[OA\Post(path: '/api/v1/customers/payments/{payment}/reverse', summary: 'Reverse a payment recorded by mistake (a reversal row; nothing is edited)', tags: ['Customers'], responses: [new OA\Response(response: 201, description: 'Reversal')])]
    public function reverse(Request $request, CustomerPayment $payment): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];
        $reversal = $this->accounts->reversePayment($payment, $reason, $request->user());

        return $this->success("Payment {$payment->number} reversed.", $this->paymentArray($reversal->load('recorder'), false), 201);
    }

    /** @return array<string, mixed> */
    private function paymentArray(CustomerPayment $p, bool $reversed): array
    {
        return [
            'id' => $p->id,
            'number' => $p->number,
            'amountCents' => $p->amount_cents,
            'method' => $p->method,
            'reference' => $p->reference,
            'receivedAt' => $p->received_at->toIso8601String(),
            'note' => $p->note,
            'isReversal' => $p->reverses_id !== null,
            'reversed' => $reversed,
            'recordedBy' => $p->recorder?->name,
        ];
    }

    /** Balances are financial data: people who take payments, set credit, or read financial reports. */
    private function requireAccountsAccess(Request $request): void
    {
        abort_unless($request->user()->canAny([Permissions::CUSTOMERS_PAYMENTS, Permissions::CUSTOMERS_CREDIT, Permissions::REPORTS_FINANCIAL_VIEW]), 403);
    }
}
