<?php

namespace Modules\Auth\Services;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;

class AuthService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Verify credentials. Failures return one generic message so the response
     * never reveals whether an email exists.
     *
     * @throws AuthenticationException
     */
    public function attempt(string $email, string $password): User
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->audit->log('auth.login.failed', $user, reason: 'Invalid credentials', userId: $user?->id);

            throw new AuthenticationException('These credentials do not match our records.');
        }

        if (! $user->is_active) {
            $this->audit->log('auth.login.blocked', $user, reason: 'Account inactive', userId: $user->id);

            throw new AuthenticationException('This account is disabled. Contact your administrator.');
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('auth.login', $user, userId: $user->id);

        return $user;
    }

    public function recordLogout(User $user): void
    {
        $this->audit->log('auth.logout', $user, userId: $user->id);
    }
}
