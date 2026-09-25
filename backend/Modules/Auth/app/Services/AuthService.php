<?php

namespace Modules\Auth\Services;

use App\Support\PhoneNumber;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
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

        return $this->completeLogin($user, 'auth.login');
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

        return $this->completeLogin($user, 'auth.pin-login', $till);
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
    private function completeLogin(User $user, string $action, ?Till $till = null): User
    {
        if (! $user->is_active) {
            $this->audit->log("{$action}.blocked", $user, reason: 'Account inactive', userId: $user->id, branchId: $till?->branch_id);

            throw new AuthenticationException('This account is disabled. Contact your administrator.');
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log($action, $user, userId: $user->id, branchId: $till?->branch_id, reference: $till ? "till:{$till->id}" : null);

        return $user;
    }
}
