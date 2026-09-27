<?php

namespace Modules\Expenses\Services;

use App\Services\DocumentNumberService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Expenses\Models\Expense;
use Modules\Organisation\Models\Branch;
use Modules\Organisation\Models\Till;
use Modules\Organisation\Services\BranchAccessService;
use Modules\Sales\Models\Shift;
use Modules\Sales\Services\ShiftService;
use Modules\Sales\Services\TillApprovalService;

/**
 * Expenses (maker–checker): recorded in the back office, approved by someone else with
 * expenses.approve at that branch. Cash paid out of a till drawer is witnessed with a manager's
 * PIN, counts as approved, and lowers the cash expected at cash-up.
 */
class ExpenseService
{
    public const NUMBER_PREFIX = 'EXP';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentNumberService $numbers,
        private readonly BranchAccessService $branches,
        private readonly ShiftService $shifts,
        private readonly TillApprovalService $approvals,
    ) {}

    /** @param array{branchId: int, categoryId: int, amountCents: int, paidFrom: string, payee?: string|null, description: string, reference?: string|null, spentOn: string} $data */
    public function request(array $data, User $user): Expense
    {
        if (! $user->can(Permissions::EXPENSES_REQUEST)) {
            throw new AuthorizationException;
        }
        $this->requireBranch($user, $data['branchId']);

        return DB::transaction(function () use ($data, $user) {
            $expense = Expense::query()->create([
                'number' => $this->numbers->next(Branch::query()->findOrFail($data['branchId']), self::NUMBER_PREFIX),
                'branch_id' => $data['branchId'],
                'category_id' => $data['categoryId'],
                'amount_cents' => $data['amountCents'],
                'paid_from' => $data['paidFrom'],
                'payee' => $data['payee'] ?? null,
                'description' => trim($data['description']),
                'reference' => $data['reference'] ?? null,
                'spent_on' => $data['spentOn'],
                'status' => Expense::PENDING,
                'requested_by' => $user->id,
            ]);
            $this->audit->log('expenses.requested', $expense, after: $this->snapshot($expense), userId: $user->id, branchId: $expense->branch_id, reference: $expense->number);

            return $expense;
        });
    }

    public function approve(Expense $expense, User $approver, ?string $note): Expense
    {
        return $this->review($expense, $approver, Expense::APPROVED, $note, 'expenses.approved');
    }

    public function reject(Expense $expense, User $approver, string $note): Expense
    {
        return $this->review($expense, $approver, Expense::REJECTED, $note, 'expenses.rejected');
    }

    /** Correct an approved expense: a reversal row, approved by the same rules. Till payouts are final. */
    public function reverse(Expense $expense, User $user, string $reason): Expense
    {
        if (! $user->can(Permissions::EXPENSES_APPROVE)) {
            throw new AuthorizationException;
        }
        $this->requireBranch($user, $expense->branch_id);
        if ($expense->status !== Expense::APPROVED || $expense->reverses_id !== null) {
            throw ValidationException::withMessages(['expense' => 'Only an approved expense can be reversed.']);
        }
        if ($expense->paid_from === 'till') {
            throw ValidationException::withMessages(['expense' => 'Cash paid out of a till is settled at that shift\'s cash-up.']);
        }
        if (Expense::query()->where('reverses_id', $expense->id)->exists()) {
            throw ValidationException::withMessages(['expense' => 'This expense has already been reversed.']);
        }

        return DB::transaction(function () use ($expense, $user, $reason) {
            $reversal = Expense::query()->forceCreate([
                'number' => $this->numbers->next($expense->branch, self::NUMBER_PREFIX),
                'branch_id' => $expense->branch_id,
                'category_id' => $expense->category_id,
                'amount_cents' => -$expense->amount_cents,
                'paid_from' => $expense->paid_from,
                'payee' => $expense->payee,
                'description' => "Reversal of {$expense->number}: {$reason}",
                'reference' => $expense->number,
                'spent_on' => now()->toDateString(),
                'status' => Expense::APPROVED,
                'requested_by' => $user->id,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_note' => $reason,
                'reverses_id' => $expense->id,
            ]);
            $this->audit->log('expenses.reversed', $expense, reason: $reason, after: ['reversal' => $reversal->number], userId: $user->id, branchId: $expense->branch_id, reference: $reversal->number);

            return $reversal;
        });
    }

    /**
     * Cash paid out of the drawer during a shift (e.g. a delivery, casual labour), witnessed by
     * a manager's PIN. It lowers the cash the drawer should hold at cash-up.
     *
     * @param  array{categoryId: int, amountCents: int, payee?: string|null, description: string, approvalToken?: string|null}  $data
     */
    public function tillPayout(Till $till, User $cashier, array $data): Expense
    {
        $shift = $this->shifts->openShiftOn($till);
        if (! $shift || $shift->user_id !== $cashier->id) {
            throw ValidationException::withMessages(['shift' => 'Only the cashier on this open shift can pay out cash.']);
        }
        $witness = $this->approvals->consume($data['approvalToken'] ?? null, 'payout', $till, $cashier);

        return DB::transaction(function () use ($till, $cashier, $data, $shift, $witness) {
            $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);
            if ($data['amountCents'] > $this->shifts->cashInDrawer($shift)) {
                throw ValidationException::withMessages(['amountCents' => 'That is more cash than the drawer should hold.']);
            }

            $expense = Expense::query()->forceCreate([
                'number' => $this->numbers->next($till->branch, self::NUMBER_PREFIX),
                'branch_id' => $till->branch_id,
                'category_id' => $data['categoryId'],
                'amount_cents' => $data['amountCents'],
                'paid_from' => 'till',
                'shift_id' => $shift->id,
                'payee' => $data['payee'] ?? null,
                'description' => trim($data['description']),
                'spent_on' => now()->toDateString(),
                'status' => Expense::APPROVED,
                'requested_by' => $cashier->id,
                'reviewed_by' => $witness,
                'reviewed_at' => now(),
                'review_note' => 'Witnessed at the till with a manager\'s PIN.',
            ]);
            $shift->forceFill(['payouts_cents' => $shift->payouts_cents + $data['amountCents']])->save();
            $this->audit->log('expenses.till_payout', $expense, after: $this->snapshot($expense), userId: $cashier->id, approverId: $witness,
                branchId: $till->branch_id, reference: "till:{$till->id}");

            return $expense;
        });
    }

    private function review(Expense $expense, User $approver, string $status, ?string $note, string $action): Expense
    {
        if (! $approver->can(Permissions::EXPENSES_APPROVE)) {
            throw new AuthorizationException;
        }
        $this->requireBranch($approver, $expense->branch_id);
        if ($expense->status !== Expense::PENDING) {
            throw ValidationException::withMessages(['expense' => 'This expense has already been reviewed.']);
        }
        if ($expense->requested_by === $approver->id) {
            throw new AuthorizationException('Someone else must approve an expense you recorded.');
        }

        return DB::transaction(function () use ($expense, $approver, $status, $note, $action) {
            $expense->forceFill(['status' => $status, 'reviewed_by' => $approver->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            $this->audit->log($action, $expense, reason: $note, userId: $expense->requested_by, approverId: $approver->id, branchId: $expense->branch_id, reference: $expense->number);

            return $expense;
        });
    }

    private function requireBranch(User $user, int $branchId): void
    {
        if (! $this->branches->canAccess($user, $branchId)) {
            throw new AuthorizationException('You do not work at this branch.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Expense $e): array
    {
        return ['amount_cents' => $e->amount_cents, 'category_id' => $e->category_id, 'paid_from' => $e->paid_from, 'payee' => $e->payee, 'description' => $e->description];
    }
}
