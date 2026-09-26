<?php

namespace Modules\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Settings\Services\SettingsService;

/** Changing your own password, and when a change is forced (set by an admin, or too old). */
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
}
