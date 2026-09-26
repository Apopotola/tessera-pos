<?php

namespace Modules\Customers\Services;

use App\Services\DocumentNumberService;
use App\Support\Aging;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Customers\Models\Customer;
use Modules\Customers\Models\CustomerPayment;
use Modules\Organisation\Models\Branch;
use Modules\Organisation\Services\BranchAccessService;

/**
 * Customer credit accounts. What a customer owes is never stored: it is sales paid
 * "on account" (sale tenders method credit) − returns credited back − payments received.
 */
class CustomerAccountService
{
    public const CREDIT = 'credit';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentNumberService $numbers,
        private readonly BranchAccessService $branches,
    ) {}

    /** @return array<int, int> customer id => balance (cents) for customers that owe or are owed */
    public function balances(?array $customerIds = null): array
    {
        $sales = DB::table('sale_tenders as t')->join('sales as s', 's.id', '=', 't.sale_id')
            ->where('t.method', self::CREDIT)->whereNotNull('s.customer_id')
            ->when($customerIds !== null, fn ($q) => $q->whereIn('s.customer_id', $customerIds))
            ->groupBy('s.customer_id')->selectRaw('s.customer_id, SUM(t.amount_cents) AS cents')->pluck('cents', 'customer_id');
        $returns = DB::table('sale_tenders as t')->join('sale_returns as r', 'r.id', '=', 't.sale_return_id')->join('sales as s', 's.id', '=', 'r.sale_id')
            ->where('t.method', self::CREDIT)->whereNotNull('s.customer_id')
            ->when($customerIds !== null, fn ($q) => $q->whereIn('s.customer_id', $customerIds))
            ->groupBy('s.customer_id')->selectRaw('s.customer_id, SUM(t.amount_cents) AS cents')->pluck('cents', 'customer_id');
        $payments = DB::table('customer_payments')
            ->when($customerIds !== null, fn ($q) => $q->whereIn('customer_id', $customerIds))
            ->groupBy('customer_id')->selectRaw('customer_id, SUM(amount_cents) AS cents')->pluck('cents', 'customer_id');

        $balances = [];
        foreach ($sales->keys()->merge($returns->keys())->merge($payments->keys())->unique() as $id) {
            // Refund tenders are negative already; payments reduce the balance.
            $balances[(int) $id] = (int) ($sales[$id] ?? 0) + (int) ($returns[$id] ?? 0) - (int) ($payments[$id] ?? 0);
        }

        return $balances;
    }

    public function balance(Customer $customer): int
    {
        return $this->balances([$customer->id])[$customer->id] ?? 0;
    }

    /**
     * Limit, balance, what is still available and the aged balance.
     *
     * @return array<string, mixed>
     */
    public function account(Customer $customer, ?CarbonImmutable $asAt = null): array
    {
        $aging = $this->aging($customer, $asAt);
        $limit = $customer->credit_limit_cents;

        return [
            'hasAccount' => $limit !== null,
            'creditLimitCents' => $limit,
            'creditTermsDays' => $customer->credit_terms_days,
            'balanceCents' => $aging['balanceCents'],
            'availableCents' => $limit === null ? null : max(0, $limit - $aging['balanceCents']),
            'overdueCents' => $aging['overdueCents'],
            'aging' => $aging['buckets'],
            'oldestDays' => $aging['oldestDays'],
        ];
    }

    /** @return array{balanceCents: int, buckets: array<string, int>, overdueCents: int, oldestDays: int|null} */
    public function aging(Customer $customer, ?CarbonImmutable $asAt = null): array
    {
        $asAt ??= CarbonImmutable::now();
        $entries = collect($this->entries($customer, null, $asAt->endOfDay()));
        // Sales are what is owed; returns, payments and their reversals net off against them.
        $sales = $entries->where('type', 'sale');
        $debits = $sales->map(fn ($e) => ['date' => $e['date'], 'due' => $e['dueDate'], 'amount' => $e['amountCents']])->values()->all();
        $credits = -$entries->where('type', '!=', 'sale')->sum('amountCents');

        return Aging::of($debits, $credits, $asAt);
    }

    /**
     * Statement for a period: opening balance, each document with a running balance, closing balance.
     *
     * @return array<string, mixed>
     */
    public function statement(Customer $customer, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $opening = (int) collect($this->entries($customer, null, $from))->sum('amountCents');
        $running = $opening;
        $lines = array_map(function (array $entry) use (&$running) {
            $running += $entry['amountCents'];

            return [...$entry, 'balanceCents' => $running];
        }, $this->entries($customer, $from, $to));

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'openingCents' => $opening, 'lines' => $lines, 'closingCents' => $running];
    }

    /**
     * Every document that changed the account, oldest first. Positive = the customer owes more.
     *
     * @return list<array{date: string, dueDate: string|null, type: string, reference: string, description: string, amountCents: int}>
     */
    public function entries(Customer $customer, ?CarbonImmutable $from, CarbonImmutable $to): array
    {
        $window = fn ($q, string $column) => $q->when($from, fn ($q) => $q->where($column, '>=', $from))->where($column, '<', $to);

        $sales = $window(DB::table('sale_tenders as t')->join('sales as s', 's.id', '=', 't.sale_id')
            ->where('t.method', self::CREDIT)->where('s.customer_id', $customer->id), 's.completed_at')
            ->get(['s.completed_at as at', 's.number', 't.amount_cents'])
            ->map(fn ($r) => [
                'date' => CarbonImmutable::parse($r->at)->toIso8601String(),
                'dueDate' => CarbonImmutable::parse($r->at)->addDays($customer->credit_terms_days)->toDateString(),
                'type' => 'sale', 'reference' => $r->number, 'description' => 'Sale on account', 'amountCents' => (int) $r->amount_cents,
            ]);
        $returns = $window(DB::table('sale_tenders as t')->join('sale_returns as r', 'r.id', '=', 't.sale_return_id')->join('sales as s', 's.id', '=', 'r.sale_id')
            ->where('t.method', self::CREDIT)->where('s.customer_id', $customer->id), 'r.created_at')
            ->get(['r.created_at as at', 'r.number', 's.number as sale_number', 't.amount_cents'])
            ->map(fn ($r) => [
                'date' => CarbonImmutable::parse($r->at)->toIso8601String(), 'dueDate' => null,
                'type' => 'return', 'reference' => $r->number, 'description' => "Return of {$r->sale_number}", 'amountCents' => (int) $r->amount_cents,
            ]);
        $payments = $window(DB::table('customer_payments')->where('customer_id', $customer->id), 'received_at')
            ->get(['received_at as at', 'number', 'method', 'reference', 'amount_cents', 'reverses_id'])
            ->map(fn ($r) => [
                'date' => CarbonImmutable::parse($r->at)->toIso8601String(), 'dueDate' => null,
                'type' => $r->reverses_id ? 'reversal' : 'payment', 'reference' => $r->number,
                'description' => ($r->reverses_id ? 'Payment reversed' : 'Payment · '.(CustomerPayment::METHODS[$r->method] ?? $r->method)).($r->reference ? " {$r->reference}" : ''),
                'amountCents' => -(int) $r->amount_cents,
            ]);

        return $sales->concat($returns)->concat($payments)->sortBy('date')->values()->all();
    }

    /** Open, change or close (null limit) a credit account. */
    public function setCredit(Customer $customer, ?int $limitCents, int $termsDays, User $user): Customer
    {
        if (! $user->can(Permissions::CUSTOMERS_CREDIT)) {
            throw new AuthorizationException;
        }
        if ($customer->isAnonymised() || ! $customer->is_active) {
            throw ValidationException::withMessages(['creditLimitCents' => 'This customer cannot have a credit account.']);
        }
        if ($limitCents === null && $this->balance($customer) > 0) {
            throw ValidationException::withMessages(['creditLimitCents' => 'The customer still owes money. Set the limit to 0 to stop new credit instead.']);
        }

        return DB::transaction(function () use ($customer, $limitCents, $termsDays, $user) {
            $before = ['credit_limit_cents' => $customer->credit_limit_cents, 'credit_terms_days' => $customer->credit_terms_days];
            $customer->forceFill(['credit_limit_cents' => $limitCents, 'credit_terms_days' => $termsDays])->save();
            $this->audit->log('customers.credit.updated', $customer, $before, ['credit_limit_cents' => $limitCents, 'credit_terms_days' => $termsDays], userId: $user->id);

            return $customer;
        });
    }

    /** @param array{amountCents: int, method: string, reference?: string|null, branchId: int, receivedAt?: string|null, note?: string|null} $data */
    public function receivePayment(Customer $customer, array $data, User $user): CustomerPayment
    {
        if (! $user->can(Permissions::CUSTOMERS_PAYMENTS)) {
            throw new AuthorizationException;
        }
        if (! $this->branches->canAccess($user, $data['branchId'])) {
            throw new AuthorizationException('You cannot record payments at this branch.');
        }
        if ($customer->credit_limit_cents === null && $this->balance($customer) <= 0) {
            throw ValidationException::withMessages(['amountCents' => 'This customer has no credit account and owes nothing.']);
        }

        return DB::transaction(function () use ($customer, $data, $user) {
            $payment = CustomerPayment::query()->create([
                'number' => $this->numbers->next(Branch::query()->findOrFail($data['branchId']), 'RCP'),
                'customer_id' => $customer->id,
                'branch_id' => $data['branchId'],
                'amount_cents' => $data['amountCents'],
                'method' => $data['method'],
                'reference' => isset($data['reference']) ? mb_strtoupper(trim($data['reference'])) : null,
                'received_at' => $data['receivedAt'] ?? now(),
                'note' => $data['note'] ?? null,
                'recorded_by' => $user->id,
            ]);
            $this->audit->log('customers.payment.received', $payment, after: ['customer_id' => $customer->id, 'amount_cents' => $payment->amount_cents, 'method' => $payment->method],
                userId: $user->id, branchId: $payment->branch_id, reference: $payment->number);

            return $payment;
        });
    }

    /** Cancel a payment recorded by mistake: a reversal row, never an edit. */
    public function reversePayment(CustomerPayment $payment, string $reason, User $user): CustomerPayment
    {
        if (! $user->can(Permissions::CUSTOMERS_PAYMENTS) || ! $this->branches->canAccess($user, $payment->branch_id)) {
            throw new AuthorizationException;
        }
        if ($payment->reverses_id !== null || CustomerPayment::query()->where('reverses_id', $payment->id)->exists()) {
            throw ValidationException::withMessages(['payment' => 'This payment has already been reversed.']);
        }

        return DB::transaction(function () use ($payment, $reason, $user) {
            $reversal = CustomerPayment::query()->create([
                'number' => $this->numbers->next(Branch::query()->findOrFail($payment->branch_id), 'RCP'),
                'customer_id' => $payment->customer_id,
                'branch_id' => $payment->branch_id,
                'amount_cents' => -$payment->amount_cents,
                'method' => $payment->method,
                'reference' => $payment->number,
                'received_at' => now(),
                'note' => $reason,
                'reverses_id' => $payment->id,
                'recorded_by' => $user->id,
            ]);
            $this->audit->log('customers.payment.reversed', $payment, reason: $reason, after: ['reversal' => $reversal->number], userId: $user->id, branchId: $payment->branch_id, reference: $reversal->number);

            return $reversal;
        });
    }

    /**
     * Before a sale on account: does it fit the limit? The till asks a manager when it does not
     * (or always, per Settings → Payments → credit sale approval).
     *
     * @return array{balanceCents: int, limitCents: int, overLimit: bool}
     */
    public function creditCheck(Customer $customer, int $amountCents): array
    {
        if ($customer->credit_limit_cents === null) {
            throw ValidationException::withMessages(['tenders' => "{$customer->name} has no credit account."]);
        }
        $balance = $this->balance($customer);

        return ['balanceCents' => $balance, 'limitCents' => (int) $customer->credit_limit_cents, 'overLimit' => $balance + $amountCents > $customer->credit_limit_cents];
    }
}
