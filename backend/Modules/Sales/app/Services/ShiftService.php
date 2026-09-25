<?php

namespace Modules\Sales\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Organisation\Models\Till;
use Modules\Sales\Models\ParkedSale;
use Modules\Sales\Models\SaleTender;
use Modules\Sales\Models\Shift;

/**
 * Cashier shifts on a till. One open shift per till and per cashier (also enforced by
 * partial unique indexes). Close is a blind count: the cashier enters counted cash
 * before seeing what was expected.
 */
class ShiftService
{
    public function __construct(private readonly AuditLogger $audit) {}

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
    public function close(Shift $shift, User $user, int $countedCashCents, ?string $note): Shift
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

        return DB::transaction(function () use ($shift, $user, $countedCashCents, $note) {
            // Float + cash taken − cash refunded (refund tenders are negative). Cash drops come later.
            $cashMovement = (int) SaleTender::query()->where('shift_id', $shift->id)->where('method', SaleTender::CASH)->sum('amount_cents');
            $expected = $shift->opening_float_cents + $cashMovement;

            $shift->forceFill([
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

            return $shift;
        });
    }
}
