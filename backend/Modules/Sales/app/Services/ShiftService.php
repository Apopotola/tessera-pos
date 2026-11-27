<?php

namespace Modules\Sales\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Services\ApprovalPolicy;
use Modules\Authorization\Support\Permissions;
use Modules\Organisation\Models\Till;
use Modules\Sales\Events\ShiftClosed;
use Modules\Sales\Models\CashCheck;
use Modules\Sales\Models\CashDrop;
use Modules\Sales\Models\ParkedSale;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleTender;
use Modules\Sales\Models\Shift;
use Modules\Sales\Support\Denominations;

/**
 * Cashier shifts on a till. One open shift per till and per cashier (also enforced by
 * partial unique indexes). Close is a blind count: the cashier enters counted cash
 * before seeing what was expected. On a shared till (Settings → Sales) the one open
 * shift is the till's session for the day: every waiter sells on it and only a
 * manager ends and counts it.
 */
class ShiftService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TillApprovalService $approvals,
        private readonly ApprovalPolicy $policy,
        private readonly TillPolicy $till,
    ) {}

    /**
     * May this person sell, drop cash or pay out on this open shift? Its own cashier always;
     * on a shared till (Settings → Sales) every waiter works on the one open shift.
     */
    public function worksOn(Shift $shift, Till $till, User $user): bool
    {
        return $shift->till_id === $till->id && $shift->isOpen()
            && ($shift->user_id === $user->id || $this->till->sharedTill($till));
    }

    /**
     * Cash in the drawer right now: float + cash taken − cash refunded − drops to the safe − expenses
     * paid out, corrected by the differences found at cash checks (each check counts from what it found).
     */
    public function cashInDrawer(Shift $shift): int
    {
        $cash = (int) SaleTender::query()->where('shift_id', $shift->id)->where('method', SaleTender::CASH)->sum('amount_cents');
        $found = (int) CashCheck::query()->where('shift_id', $shift->id)->sum('variance_cents');

        return $shift->opening_float_cents + $cash - (int) CashDrop::query()->where('shift_id', $shift->id)->sum('amount_cents') - (int) $shift->payouts_cents + $found;
    }

    public function lastCashCheck(Shift $shift): ?CashCheck
    {
        return CashCheck::query()->where('shift_id', $shift->id)->latest('created_at')->latest('id')->first();
    }

    /**
     * Till summary for a manager, without closing: since the last cash check (or the opening), sales by
     * waiter and takings by payment method, drops, pay-outs, and the cash the drawer should hold.
     * With blind cash-up on, expected cash stays hidden until it is counted.
     *
     * @return array<string, mixed>
     */
    public function summary(Shift $shift, Till $till, User $manager): array
    {
        if (! $manager->can(Permissions::SHIFTS_CASHUP_APPROVE)) {
            throw new AuthorizationException('Only a manager can see the till summary.');
        }
        if ($shift->till_id !== $till->id || ! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => 'This till is not open.']);
        }

        $last = $this->lastCashCheck($shift);
        $since = $last->created_at ?? $shift->opened_at;

        // The period starts after the last sale and payment the previous check counted (timestamps are only to the second).
        $byWaiter = Sale::query()->where('shift_id', $shift->id)->where('id', '>', $last->last_sale_id ?? 0)
            ->selectRaw('user_id, count(*) as sales_count, sum(total_cents) as total_cents')
            ->groupBy('user_id')->get();
        $names = User::query()->whereIn('id', $byWaiter->pluck('user_id'))->pluck('name', 'id');
        $byMethod = SaleTender::query()->where('shift_id', $shift->id)->where('id', '>', $last->last_tender_id ?? 0)
            ->selectRaw('method, sum(amount_cents) as amount_cents')->groupBy('method')->pluck('amount_cents', 'method');
        $blind = $this->till->blindCashUp($till);

        return [
            'shiftId' => $shift->id,
            'openedAt' => $shift->opened_at->toIso8601String(),
            'since' => $since->toIso8601String(),
            'lastCheck' => $last ? ['id' => $last->id, 'at' => $last->created_at->toIso8601String(), 'countedCashCents' => $last->counted_cash_cents] : null,
            // The cash the period started with: the opening float, or what the last check counted.
            'startingCashCents' => $last ? $last->counted_cash_cents : $shift->opening_float_cents,
            'waiters' => $byWaiter->map(fn ($row) => [
                'userId' => (int) $row->user_id,
                'name' => $names[$row->user_id] ?? '—',
                'salesCount' => (int) $row->sales_count,
                'totalCents' => (int) $row->total_cents,
            ])->sortByDesc('totalCents')->values()->all(),
            'methods' => collect([SaleTender::MPESA, SaleTender::CASH, SaleTender::CARD, SaleTender::CREDIT])
                ->map(fn (string $m) => ['method' => $m, 'amountCents' => (int) ($byMethod[$m] ?? 0)])->all(),
            'salesCount' => (int) $byWaiter->sum('sales_count'),
            'salesTotalCents' => (int) $byWaiter->sum('total_cents'),
            'dropsCents' => (int) $shift->drops_cents - ($last->drops_total_cents ?? 0),
            'payoutsCents' => (int) $shift->payouts_cents - ($last->payouts_total_cents ?? 0),
            'blind' => $blind,
            'expectedCashCents' => $blind ? null : $this->cashInDrawer($shift),
        ];
    }

    /**
     * Cash check on a shared till: a manager counts the drawer while the till stays open and waiters
     * keep selling. Expected, counted and the difference are recorded, and the next count starts
     * from the cash found.
     *
     * @param  array<int|string, int>|null  $pieces  denomination (cents) => pieces; when given, it is the count
     */
    public function cashCheck(Shift $shift, Till $till, User $manager, int $countedCashCents, ?string $note, ?array $pieces = null): CashCheck
    {
        if (! $manager->can(Permissions::SHIFTS_CASHUP_APPROVE)) {
            throw new AuthorizationException('Only a manager can count the till.');
        }
        if (! $this->till->sharedTill($till)) {
            throw ValidationException::withMessages(['shift' => 'Cash checks are for a shared till that stays open. End the shift to count this till.']);
        }
        if ($shift->till_id !== $till->id || ! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => 'This till is not open.']);
        }

        $breakdown = null;
        if ($pieces !== null) {
            ['total' => $countedCashCents, 'breakdown' => $breakdown] = Denominations::total($pieces);
        }

        return DB::transaction(function () use ($shift, $manager, $countedCashCents, $note, $breakdown) {
            // Drops and pay-outs lock the shift too, so the running totals below are exact.
            $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);
            $last = $this->lastCashCheck($shift);
            $expected = $this->cashInDrawer($shift);

            $check = CashCheck::query()->create([
                'shift_id' => $shift->id,
                'branch_id' => $shift->branch_id,
                'till_id' => $shift->till_id,
                'user_id' => $manager->id,
                'period_started_at' => $last->created_at ?? $shift->opened_at,
                'expected_cash_cents' => $expected,
                'counted_cash_cents' => $countedCashCents,
                'variance_cents' => $countedCashCents - $expected,
                'count_breakdown' => $breakdown,
                'drops_total_cents' => (int) $shift->drops_cents,
                'payouts_total_cents' => (int) $shift->payouts_cents,
                'last_sale_id' => Sale::query()->where('shift_id', $shift->id)->max('id'),
                'last_tender_id' => SaleTender::query()->where('shift_id', $shift->id)->max('id'),
                'note' => $note ? trim($note) : null,
            ]);

            $this->audit->log('sales.shift.cash_check', $check, after: [
                'expected_cash_cents' => $expected,
                'counted_cash_cents' => $countedCashCents,
                'variance_cents' => $check->variance_cents,
            ], reason: $note, userId: $manager->id, branchId: $shift->branch_id, reference: "till:{$shift->till_id}");

            return $check;
        });
    }

    /** Manager sign-off of a cash check. Never by the manager who counted it, unless own approvals are allowed. */
    public function reviewCashCheck(CashCheck $check, User $manager, ?string $note): CashCheck
    {
        if (! $manager->can(Permissions::SHIFTS_CASHUP_APPROVE)) {
            throw new AuthorizationException;
        }
        if ($check->user_id === $manager->id && ! $this->policy->mayApproveOwn($manager)) {
            throw new AuthorizationException('You cannot sign off your own cash check.');
        }
        if ($check->reviewed_at !== null) {
            throw ValidationException::withMessages(['check' => 'This cash check is already signed off.']);
        }
        if ($check->variance_cents !== 0 && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Say what was done about the difference.']);
        }

        return DB::transaction(function () use ($check, $manager, $note) {
            $check->forceFill(['reviewed_by' => $manager->id, 'reviewed_at' => now(), 'review_note' => $note ? trim($note) : null])->save();
            $this->audit->log('sales.shift.cash_check_signed_off', $check, after: ['variance_cents' => $check->variance_cents], reason: $note,
                userId: $manager->id, branchId: $check->branch_id);

            return $check;
        });
    }

    /** Move excess cash to the safe, witnessed by a manager's PIN. */
    public function cashDrop(Shift $shift, Till $till, User $cashier, int $amountCents, ?string $note, ?string $approvalToken): CashDrop
    {
        if (! $this->worksOn($shift, $till, $cashier)) {
            throw ValidationException::withMessages(['shift' => 'Only the cashier on this open shift can record a cash drop.']);
        }
        $witness = $this->approvals->consume($approvalToken, 'cash_drop', $till, $cashier);

        return DB::transaction(function () use ($shift, $cashier, $amountCents, $note, $witness) {
            $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);
            if ($amountCents > $this->cashInDrawer($shift)) {
                throw ValidationException::withMessages(['amountCents' => 'That is more cash than the drawer should hold.']);
            }

            $drop = CashDrop::query()->create([
                'shift_id' => $shift->id,
                'branch_id' => $shift->branch_id,
                'user_id' => $cashier->id,
                'witnessed_by' => $witness,
                'amount_cents' => $amountCents,
                'note' => $note,
            ]);
            $shift->forceFill(['drops_cents' => $shift->drops_cents + $amountCents])->save();

            $this->audit->log('sales.shift.cash_drop', $shift, after: ['amount_cents' => $amountCents], reason: $note,
                userId: $cashier->id, approverId: $witness, branchId: $shift->branch_id, reference: "till:{$shift->till_id}");

            return $drop;
        });
    }

    /** After a blind close shows a difference, the cashier says why (until a manager signs off). */
    public function explainVariance(Shift $shift, User $user, string $reason): Shift
    {
        if ($shift->isOpen() || (int) $shift->variance_cents === 0) {
            throw ValidationException::withMessages(['reason' => 'Only a closed shift with a difference needs a reason.']);
        }
        if ($shift->user_id !== $user->id) {
            throw new AuthorizationException('Only the cashier who counted can explain the difference.');
        }
        if ($shift->reviewed_at !== null) {
            throw ValidationException::withMessages(['reason' => 'This cash-up has already been signed off.']);
        }

        $shift->forceFill(['variance_reason' => trim($reason)])->save();
        $this->audit->log('sales.shift.variance_explained', $shift, after: ['variance_cents' => $shift->variance_cents], reason: $reason,
            userId: $user->id, branchId: $shift->branch_id);

        return $shift;
    }

    /** Manager sign-off of a closed cash-up. Never by the cashier who counted it (segregation of duties). */
    public function review(Shift $shift, User $manager, ?string $note): Shift
    {
        if (! $manager->can(Permissions::SHIFTS_CASHUP_APPROVE)) {
            throw new AuthorizationException;
        }
        if ($shift->user_id === $manager->id && ! $this->policy->mayApproveOwn($manager)) {
            throw new AuthorizationException('You cannot sign off your own cash-up.');
        }
        if ($shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => 'The shift is still open.']);
        }
        if ($shift->reviewed_at !== null) {
            throw ValidationException::withMessages(['shift' => 'This cash-up is already signed off.']);
        }
        if ((int) $shift->variance_cents !== 0 && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Say what was done about the difference.']);
        }

        $shift->forceFill(['reviewed_by' => $manager->id, 'reviewed_at' => now(), 'review_note' => $note ? trim($note) : null])->save();
        $this->audit->log('sales.shift.signed_off', $shift, after: ['variance_cents' => $shift->variance_cents], reason: $note,
            userId: $manager->id, branchId: $shift->branch_id);

        return $shift;
    }

    public function openShiftOn(Till $till): ?Shift
    {
        return Shift::query()->with('user')->where('till_id', $till->id)->whereNull('closed_at')->first();
    }

    /**
     * Opens a shift, or resumes the cashier's own open shift on this till.
     *
     * @return array{shift: Shift, resumed: bool}
     */
    public function start(Till $till, User $user, int $openingFloatCents): array
    {
        if (! $user->can(Permissions::SALES_SELL)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($till, $user, $openingFloatCents) {
            // Lock the till row so two cashiers cannot open it at the same moment.
            Till::query()->whereKey($till->id)->lockForUpdate()->first();

            $current = $this->openShiftOn($till);
            // A shared till has one session for everyone: a waiter joins the open one.
            if ($current && ($current->user_id === $user->id || $this->till->sharedTill($till))) {
                return ['shift' => $current, 'resumed' => true];
            }

            if ($current) {
                throw ValidationException::withMessages([
                    'shift' => "{$till->name} already has an open shift for {$current->user->name}. They must end it first.",
                ]);
            }

            $elsewhere = Shift::query()->with('till')->where('user_id', $user->id)->whereNull('closed_at')->first();
            if ($elsewhere) {
                throw ValidationException::withMessages([
                    'shift' => "You still have an open shift on {$elsewhere->till->name}. End it there first.",
                ]);
            }

            $shift = Shift::query()->create([
                'branch_id' => $till->branch_id,
                'till_id' => $till->id,
                'user_id' => $user->id,
                'opening_float_cents' => $openingFloatCents,
                'opened_at' => now(),
            ]);

            $this->audit->log('sales.shift.opened', $shift, after: ['opening_float_cents' => $openingFloatCents],
                userId: $user->id, branchId: $till->branch_id, reference: "till:{$till->id}");

            return ['shift' => $shift->load('user'), 'resumed' => false];
        });
    }

    /**
     * Blind close: expected cash (float + cash sales − cash refunds) is only
     * computed after the count is submitted.
     */
    /** @param array<int|string, int>|null $pieces denomination (cents) => pieces; when given, it is the count */
    public function close(Shift $shift, User $user, int $countedCashCents, ?string $note, ?array $pieces = null): Shift
    {
        if ($shift->user_id !== $user->id && ! $user->can(Permissions::SHIFTS_CASHUP_APPROVE)) {
            throw new AuthorizationException('Only the cashier or a manager can end this shift.');
        }
        if ($this->till->sharedTill($shift->loadMissing('till')->till) && ! $user->can(Permissions::SHIFTS_CASHUP_APPROVE)) {
            throw new AuthorizationException('Everyone sells on this till: only a manager can close and count it.');
        }

        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => 'This shift is already closed.']);
        }

        $parked = ParkedSale::query()->where('till_id', $shift->till_id)->count();
        if ($parked > 0) {
            throw ValidationException::withMessages(['shift' => "{$parked} parked sale(s) on this till. Recall and finish or clear them before ending the shift."]);
        }

        $breakdown = null;
        if ($pieces !== null) {
            ['total' => $countedCashCents, 'breakdown' => $breakdown] = Denominations::total($pieces);
        }

        return DB::transaction(function () use ($shift, $user, $countedCashCents, $note, $breakdown) {
            // Float + cash taken − cash refunded (refund tenders are negative) − drops to the safe.
            $expected = $this->cashInDrawer($shift);

            $shift->forceFill([
                'count_breakdown' => $breakdown,
                'closed_at' => now(),
                'expected_cash_cents' => $expected,
                'counted_cash_cents' => $countedCashCents,
                'variance_cents' => $countedCashCents - $expected,
                'close_note' => $note,
            ])->save();

            $this->audit->log('sales.shift.closed', $shift, after: [
                'expected_cash_cents' => $expected,
                'counted_cash_cents' => $countedCashCents,
                'variance_cents' => $shift->variance_cents,
            ], reason: $note, userId: $user->id, branchId: $shift->branch_id, reference: "till:{$shift->till_id}");

            ShiftClosed::dispatch($shift->id);

            return $shift;
        });
    }
}
