<?php

namespace Modules\Sales\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Organisation\Models\Till;
use Modules\Sales\Events\ShiftClosed;
use Modules\Sales\Models\CashDrop;
use Modules\Sales\Models\ParkedSale;
use Modules\Sales\Models\SaleTender;
use Modules\Sales\Models\Shift;
use Modules\Sales\Support\Denominations;

/**
 * Cashier shifts on a till. One open shift per till and per cashier (also enforced by
 * partial unique indexes). Close is a blind count: the cashier enters counted cash
 * before seeing what was expected.
 */
class ShiftService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TillApprovalService $approvals,
    ) {}

    /** Cash in the drawer right now: float + cash taken − cash refunded − drops to the safe. */
    public function cashInDrawer(Shift $shift): int
    {
        $cash = (int) SaleTender::query()->where('shift_id', $shift->id)->where('method', SaleTender::CASH)->sum('amount_cents');

        return $shift->opening_float_cents + $cash - (int) CashDrop::query()->where('shift_id', $shift->id)->sum('amount_cents');
    }

    /** Move excess cash to the safe, witnessed by a manager's PIN. */
    public function cashDrop(Shift $shift, Till $till, User $cashier, int $amountCents, ?string $note, ?string $approvalToken): CashDrop
    {
        if ($shift->till_id !== $till->id || $shift->user_id !== $cashier->id || ! $shift->isOpen()) {
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
        if ($shift->user_id === $manager->id) {
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
            if ($current && $current->user_id === $user->id) {
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
