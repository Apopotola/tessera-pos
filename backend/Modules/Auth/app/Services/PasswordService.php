<?php

namespace Modules\Auth\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Settings\Services\SettingsService;

/** Changing your own password, an admin's reset, and when a change is forced (set by an admin, or too old). */
class PasswordService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function minLength(): int
    {
        return (int) $this->settings->get('staff.password_min_length');
    }

    /** Settings → Staff → Password expiry (0 = never). */
    public function expired(User $user): bool
    {
        $days = (int) $this->settings->get('staff.password_expiry_days');
        $changed = $user->password_changed_at ?? $user->created_at;

        return $days > 0 && $changed !== null && $changed->lt(now()->subDays($days));
    }

    public function mustChange(User $user): bool
    {
        return $user->must_change_password || $this->expired($user);
    }

    public function change(User $user, string $current, string $new): void
    {
        if (! Hash::check($current, $user->password)) {
            throw ValidationException::withMessages(['currentPassword' => 'Your current password is not right.']);
        }
        if (Hash::check($new, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password you have not used just now.']);
        }

        DB::transaction(function () use ($user, $new) {
            $user->forceFill(['password' => $new, 'password_changed_at' => now(), 'must_change_password' => false])->save();
            $this->audit->log('auth.password.changed', $user, userId: $user->id);
        });
    }

    /**
     * An administrator sets a temporary password for someone who forgot theirs. The person must
     * choose their own at next sign-in, and any open back-office session of theirs is ended.
     */
    public function reset(User $actor, User $user, string $new): void
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['password' => 'Change your own password from your account menu.']);
        }
        if ($user->hasRole(Roles::TESSERA_ADMIN) && ! $actor->hasRole(Roles::TESSERA_ADMIN)) {
            throw new AuthorizationException('Only Tessera support can reset a Tessera support account.');
        }
        if ($user->hasRole(Roles::OWNER) && ! $actor->hasAnyRole([Roles::OWNER, Roles::TESSERA_ADMIN])) {
            throw new AuthorizationException('Only an Owner or Tessera support can reset an Owner\'s password.');
        }

        DB::transaction(function () use ($actor, $user, $new) {
            $user->forceFill(['password' => $new, 'password_changed_at' => now(), 'must_change_password' => true])->save();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
            // The password itself never reaches the audit log.
            $this->audit->log('auth.password.reset', $user, userId: $actor->id);
        });
    }
}
