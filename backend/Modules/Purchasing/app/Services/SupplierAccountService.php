<?php

namespace Modules\Purchasing\Services;

use App\Support\Aging;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Purchasing\Models\SupplierPayment;

/**
 * What we owe each supplier: invoices − payments − credit notes for returned stock.
 * Never a stored balance. Invoices that do not match the goods received are on query:
 * still owed, but shown apart so they are not paid before the supplier answers.
 */
class SupplierAccountService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<int, int> supplier id => balance (cents) */
    public function balances(?array $supplierIds = null): array
    {
        $scope = fn ($q, string $column = 'supplier_id') => $q->when($supplierIds !== null, fn ($q) => $q->whereIn($column, $supplierIds));
        $invoices = $scope(DB::table('supplier_invoices'))->groupBy('supplier_id')->selectRaw('supplier_id, SUM(total_cents) AS c')->pluck('c', 'supplier_id');
        $payments = $scope(DB::table('supplier_payments'))->groupBy('supplier_id')->selectRaw('supplier_id, SUM(amount_cents) AS c')->pluck('c', 'supplier_id');
        $credits = $scope(DB::table('supplier_returns'))->whereNotNull('credit_note_cents')->groupBy('supplier_id')->selectRaw('supplier_id, SUM(credit_note_cents) AS c')->pluck('c', 'supplier_id');

        $balances = [];
        foreach ($invoices->keys()->merge($payments->keys())->merge($credits->keys())->unique() as $id) {
            $balances[(int) $id] = (int) ($invoices[$id] ?? 0) - (int) ($payments[$id] ?? 0) - (int) ($credits[$id] ?? 0);
        }

        return $balances;
    }

    /** @return array<string, mixed> */
    public function account(Supplier $supplier, ?CarbonImmutable $asAt = null): array
    {
        $asAt ??= CarbonImmutable::now();
        $entries = collect($this->entries($supplier, null, $asAt->endOfDay()));
        // Invoices are what we owe; payments, their reversals and credit notes net off against them.
        $debits = $entries->where('type', 'invoice')->map(fn ($e) => ['date' => $e['date'], 'due' => $e['dueDate'], 'amount' => $e['amountCents']])->values()->all();
        $aging = Aging::of($debits, -$entries->where('type', '!=', 'invoice')->sum('amountCents'), $asAt);

        return [
            'balanceCents' => $aging['balanceCents'],
            'dueCents' => $aging['overdueCents'],
            'onQueryCents' => (int) SupplierInvoice::query()->where('supplier_id', $supplier->id)->where('match_status', SupplierInvoice::VARIANCE)->sum('total_cents'),
            'paymentTermsDays' => $supplier->payment_terms_days,
            'aging' => $aging['buckets'],
            'oldestDays' => $aging['oldestDays'],
        ];
    }

    /** @return array<string, mixed> */
    public function statement(Supplier $supplier, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $opening = (int) collect($this->entries($supplier, null, $from))->sum('amountCents');
        $running = $opening;
        $lines = array_map(function (array $entry) use (&$running) {
            $running += $entry['amountCents'];

            return [...$entry, 'balanceCents' => $running];
        }, $this->entries($supplier, $from, $to));

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'openingCents' => $opening, 'lines' => $lines, 'closingCents' => $running];
    }

    /**
     * Every document that changed the balance, oldest first. Positive = we owe more.
     *
     * @return list<array{date: string, dueDate: string|null, type: string, reference: string, description: string, amountCents: int}>
     */
    public function entries(Supplier $supplier, ?CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Date columns: Postgres would cast a timestamp bound to a date, so compare dates.
        // $to is exclusive: midnight means "before that day", any later time includes that day.
        $before = ($to->equalTo($to->startOfDay()) ? $to : $to->addDay())->toDateString();
        $window = fn ($q, string $column) => $q->when($from, fn ($q) => $q->where($column, '>=', $from->toDateString()))->where($column, '<', $before);

        $invoices = $window(DB::table('supplier_invoices')->where('supplier_id', $supplier->id), 'invoice_date')
            ->get(['invoice_date', 'due_date', 'invoice_number', 'total_cents', 'match_status'])
            ->map(fn ($r) => [
                'date' => (string) $r->invoice_date, 'dueDate' => (string) $r->due_date, 'type' => 'invoice', 'reference' => $r->invoice_number,
                'description' => $r->match_status === SupplierInvoice::VARIANCE ? 'Invoice (on query: does not match goods received)' : 'Invoice',
                'amountCents' => (int) $r->total_cents,
            ]);
        $payments = $window(DB::table('supplier_payments')->where('supplier_id', $supplier->id), 'paid_on')
            ->get(['paid_on', 'number', 'method', 'reference', 'amount_cents', 'reverses_id'])
            ->map(fn ($r) => [
                'date' => (string) $r->paid_on, 'dueDate' => null, 'type' => $r->reverses_id ? 'reversal' : 'payment', 'reference' => $r->number,
                'description' => ($r->reverses_id ? 'Payment reversed' : 'Payment · '.(SupplierPayment::METHODS[$r->method] ?? $r->method)).($r->reference ? " {$r->reference}" : ''),
                'amountCents' => -(int) $r->amount_cents,
            ]);
        $credits = $window(DB::table('supplier_returns')->where('supplier_id', $supplier->id)->whereNotNull('credit_note_cents'), 'credit_note_date')
            ->get(['credit_note_date', 'number', 'credit_note_ref', 'credit_note_cents'])
            ->map(fn ($r) => [
                'date' => (string) $r->credit_note_date, 'dueDate' => null, 'type' => 'credit_note', 'reference' => $r->credit_note_ref ?? $r->number,
                'description' => "Credit note for return {$r->number}", 'amountCents' => -(int) $r->credit_note_cents,
            ]);

        return $invoices->concat($payments)->concat($credits)->sortBy('date')->values()->all();
    }

    /** @param array{amountCents: int, method: string, reference?: string|null, paidOn?: string|null, note?: string|null} $data */
    public function recordPayment(Supplier $supplier, array $data, User $user): SupplierPayment
    {
        if (! $user->can(Permissions::PURCHASING_PAY)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($supplier, $data, $user) {
            $payment = $this->create([
                'supplier_id' => $supplier->id,
                'amount_cents' => $data['amountCents'],
                'method' => $data['method'],
                'reference' => isset($data['reference']) ? mb_strtoupper(trim($data['reference'])) : null,
                'paid_on' => $data['paidOn'] ?? now()->toDateString(),
                'note' => $data['note'] ?? null,
                'recorded_by' => $user->id,
            ]);
            $this->audit->log('purchasing.payment.recorded', $payment, after: ['supplier_id' => $supplier->id, 'amount_cents' => $payment->amount_cents, 'method' => $payment->method],
                userId: $user->id, reference: $payment->number);

            return $payment;
        });
    }

    public function reversePayment(SupplierPayment $payment, string $reason, User $user): SupplierPayment
    {
        if (! $user->can(Permissions::PURCHASING_PAY)) {
            throw new AuthorizationException;
        }
        if ($payment->reverses_id !== null || SupplierPayment::query()->where('reverses_id', $payment->id)->exists()) {
            throw ValidationException::withMessages(['payment' => 'This payment has already been reversed.']);
        }

        return DB::transaction(function () use ($payment, $reason, $user) {
            $reversal = $this->create([
                'supplier_id' => $payment->supplier_id,
                'amount_cents' => -$payment->amount_cents,
                'method' => $payment->method,
                'reference' => $payment->number,
                'paid_on' => now()->toDateString(),
                'note' => $reason,
                'reverses_id' => $payment->id,
                'recorded_by' => $user->id,
            ]);
            $this->audit->log('purchasing.payment.reversed', $payment, reason: $reason, after: ['reversal' => $reversal->number], userId: $user->id, reference: $reversal->number);

            return $reversal;
        });
    }

    /** Business-wide numbers SP-000001… (payments are not per branch). @param array<string, mixed> $attributes */
    private function create(array $attributes): SupplierPayment
    {
        $id = (int) DB::selectOne("SELECT nextval('supplier_payments_id_seq') AS id")->id;

        return SupplierPayment::query()->forceCreate(['id' => $id, 'number' => sprintf('SP-%06d', $id), ...$attributes]);
    }
}
