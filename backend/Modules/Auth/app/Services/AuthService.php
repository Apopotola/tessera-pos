<?php

namespace Modules\Auth\Services;

use App\Support\PhoneNumber;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Organisation\Models\Till;
use Modules\Organisation\Services\BranchAccessService;

class AuthService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BranchAccessService $branchAccess,
    ) {}

    /**
     * Back-office sign-in with email or Kenyan phone number + password.
     * Failures return one generic message so the response never reveals whether an account exists.
     * The sign-in is recorded (recordLogin) once any two-step code has been checked.
     *
     * @throws AuthenticationException
     */
    public function attempt(string $login, string $password): User
    {
        $user = $this->findByLogin($login);

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->audit->log('auth.login.failed', $user, reason: 'Invalid credentials', userId: $user?->id);

            throw new AuthenticationException('These credentials do not match our records.');
        }

        $this->assertActive($user, 'auth.login');

        return $user;
    }

    /**
     * Till sign-in: a cashier picked from the till's list enters their PIN.
     * The device (till) must be paired, and the user must be allowed to sell at its branch.
     *
     * @throws AuthenticationException
     */
    public function attemptPin(Till $till, int $userId, string $pin): User
    {
        $user = User::query()->find($userId);

        if (! $user || ! $user->pin_hash || ! Hash::check($pin, $user->pin_hash)) {
            $this->audit->log('auth.pin-login.failed', $user, reason: 'Wrong PIN', userId: $user?->id, branchId: $till->branch_id);

            throw new AuthenticationException('Wrong PIN. Try again.');
        }

        if (! $user->can(Permissions::SALES_SELL) || ! $this->branchAccess->canAccess($user, $till->branch_id)) {
            $this->audit->log('auth.pin-login.blocked', $user, reason: 'Not allowed at this till', userId: $user->id, branchId: $till->branch_id);

            throw new AuthenticationException('You are not allowed to sell at this till.');
        }

        $this->assertActive($user, 'auth.pin-login', $till);
        $this->recordLogin($user, 'auth.pin-login', $till);

        return $user;
    }

    /**
     * Unlock a till screen: the cashier who was signed in, or a manager of this branch
     * (who can then park or finish the sale). Returns the manager's id when a manager did it.
     */
    public function unlockTill(Till $till, User $cashier, int $userId, string $pin): ?int
    {
        $throttleKey = "till-unlock:{$till->id}:{$userId}";
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages(['pin' => 'Too many wrong PINs. Wait a minute and try again.']);
        }

        $user = User::query()->find($userId);
        if (! $user || ! $user->is_active || ! $user->pin_hash || ! Hash::check($pin, $user->pin_hash)) {
            RateLimiter::hit($throttleKey, 60);
            $this->audit->log('auth.till.unlock-failed', $user, reason: 'Wrong PIN', userId: $cashier->id, branchId: $till->branch_id, reference: "till:{$till->id}");
            throw ValidationException::withMessages(['pin' => 'Wrong PIN.']);
        }

        $manager = ! $user->is($cashier);
        if ($manager && (! $user->can(Permissions::SHIFTS_CASHUP_APPROVE) || ! $this->branchAccess->canAccess($user, $till->branch_id))) {
            throw ValidationException::withMessages(['pin' => "{$user->name} cannot unlock this till. The cashier or a branch manager can."]);
        }

        RateLimiter::clear($throttleKey);
        $this->audit->log('auth.till.unlocked', $cashier, userId: $cashier->id, approverId: $manager ? $user->id : null, branchId: $till->branch_id, reference: "till:{$till->id}");

        return $manager ? $user->id : null;
    }

    public function lockTill(Till $till, User $cashier, string $reason): void
    {
        $this->audit->log('auth.till.locked', $cashier, reason: $reason, userId: $cashier->id, branchId: $till->branch_id, reference: "till:{$till->id}");
    }

    public function recordLogout(User $user): void
    {
        $this->audit->log('auth.logout', $user, userId: $user->id);
    }

    private function findByLogin(string $login): ?User
    {
        $login = trim($login);

        if (str_contains($login, '@')) {
            return User::query()->where('email', mb_strtolower($login))->first();
        }

        $phone = PhoneNumber::normalize($login);

        return $phone ? User::query()->where('phone', $phone)->first() : null;
    }

    /** @throws AuthenticationException */
    private function assertActive(User $user, string $action, ?Till $till = null): void
    {
        if (! $user->is_active) {
            $this->audit->log("{$action}.blocked", $user, reason: 'Account inactive', userId: $user->id, branchId: $till?->branch_id);

            throw new AuthenticationException('This account is disabled. Contact your administrator.');
        }
    }

    public function recordLogin(User $user, string $action, ?Till $till = null, ?string $reason = null): void
    {
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log($action, $user, reason: $reason, userId: $user->id, branchId: $till?->branch_id, reference: $till ? "till:{$till->id}" : null);
    }
}
