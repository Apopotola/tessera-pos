<?php

namespace Modules\Sales\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Organisation\Models\Till;
use Modules\Organisation\Services\BranchAccessService;

/**
 * Manager approval at the till. The manager enters their own PIN on the cashier's till;
 * we hand back a short-lived, single-use token bound to that till, cashier and action,
 * which the sale or return then spends. The cashier can never approve themselves.
 */
class TillApprovalService
{
    /** action => permission the approver needs */
    public const ACTIONS = [
        'discount' => Permissions::SALES_OVERRIDE_APPROVE,
        'override' => Permissions::SALES_OVERRIDE_APPROVE,
        'void' => Permissions::SALES_VOID_APPROVE,
        'refund' => Permissions::SALES_REFUND_APPROVE,
        // Selling more than the shop floor holds (Settings → stock.below_zero = approval).
        'below_zero' => Permissions::SALES_OVERRIDE_APPROVE,
        // A sale on account over the customer's limit, or any, per Settings → Payments.
        'credit' => Permissions::SALES_OVERRIDE_APPROVE,
        // Manager witnesses cash leaving the drawer for the safe.
        'cash_drop' => Permissions::SHIFTS_CASHUP_APPROVE,
    ];

    public function __construct(private readonly BranchAccessService $branches, private readonly AuditLogger $audit) {}

    /** @return array{token: string, approver: array{id: int, name: string}, expiresInSeconds: int} */
    public function grant(Till $till, User $cashier, int $approverId, string $pin, string $action): array
    {
        $throttleKey = "till-approval:{$till->id}:{$approverId}";
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages(['pin' => 'Too many wrong PINs. Wait a minute and try again.']);
        }

        $approver = User::query()->find($approverId);
        if (! $approver || ! $approver->is_active || ! $approver->pin_hash || ! Hash::check($pin, $approver->pin_hash)) {
            RateLimiter::hit($throttleKey, 60);
            $this->audit->log('sales.approval.failed', $approver, reason: "Wrong PIN for {$action}", userId: $cashier->id, branchId: $till->branch_id);
            throw ValidationException::withMessages(['pin' => 'Wrong PIN.']);
        }

        if ($approver->is($cashier)) {
            throw new AuthorizationException('A different person must approve this.');
        }
        if (! $approver->can(self::ACTIONS[$action]) || ! $this->branches->canAccess($approver, $till->branch_id)) {
            throw new AuthorizationException("{$approver->name} cannot approve this at this branch.");
        }

        RateLimiter::clear($throttleKey);
        $token = Str::random(40);
        $ttl = (int) config('sales.approval_ttl_seconds', 300);
        Cache::put("till-approval:{$token}", [
            'approver_id' => $approver->id,
            'action' => $action,
            'till_id' => $till->id,
            'cashier_id' => $cashier->id,
        ], $ttl);

        return ['token' => $token, 'approver' => ['id' => $approver->id, 'name' => $approver->name], 'expiresInSeconds' => $ttl];
    }

    /** Spends a token; returns the approver's id. */
    public function consume(?string $token, string $action, Till $till, User $cashier): int
    {
        $grant = $token ? Cache::pull("till-approval:{$token}") : null;

        if (! is_array($grant) || $grant['action'] !== $action || $grant['till_id'] !== $till->id || $grant['cashier_id'] !== $cashier->id) {
            throw ValidationException::withMessages(['approval' => 'A manager must approve this (their PIN).']);
        }

        return $grant['approver_id'];
    }
}
