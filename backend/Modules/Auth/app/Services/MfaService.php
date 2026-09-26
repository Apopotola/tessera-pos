<?php

namespace Modules\Auth\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Auth\Support\Totp;
use Modules\Authorization\Support\Roles;
use Modules\Settings\Services\SettingsService;

/**
 * Two-step login with an authenticator app (TOTP). Required for owners and admins while
 * Settings → Staff → Two-step login is on, and always for Tessera support accounts.
 */
class MfaService
{
    public const ISSUER = 'Tessera POS';

    private const RECOVERY_CODES = 8;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function required(User $user): bool
    {
        if ($user->hasRole(Roles::TESSERA_ADMIN)) {
            return true;
        }

        return $user->hasAnyRole([Roles::OWNER, Roles::ADMIN]) && (bool) $this->settings->get('staff.two_step_login');
    }

    public function enabled(User $user): bool
    {
        return $user->mfa_enabled_at !== null;
    }

    /** @return array{secret: string, uri: string} */
    public function newSetup(User $user): array
    {
        $secret = Totp::newSecret();

        return ['secret' => $secret, 'uri' => Totp::uri($secret, $user->email, self::ISSUER)];
    }

    /**
     * Turn two-step login on once the app shows a matching code.
     *
     * @return list<string> recovery codes, shown once
     */
    public function enable(User $user, string $secret, string $code): array
    {
        $step = Totp::verify($secret, $code);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'That code does not match. Check the time on your phone and try the newest code.']);
        }

        $codes = $this->recoveryCodes();

        DB::transaction(function () use ($user, $secret, $step, $codes) {
            $user->forceFill([
                'mfa_secret' => $secret,
                'mfa_enabled_at' => now(),
                'mfa_last_step' => $step,
                'mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes),
            ])->save();
            $this->audit->log('auth.mfa.enabled', $user, userId: $user->id);
        });

        return $codes;
    }

    /** A code from the app, or one of the recovery codes (each works once). */
    public function check(User $user, string $code): bool
    {
        if (! $this->enabled($user)) {
            return false;
        }

        $step = Totp::verify((string) $user->mfa_secret, $code, $user->mfa_last_step);
        if ($step !== null) {
            $user->forceFill(['mfa_last_step' => $step])->save();

            return true;
        }

        $typed = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        $stored = (array) $user->mfa_recovery_codes;
        foreach ($stored as $i => $hash) {
            if (strlen($typed) === 10 && Hash::check($typed, $hash)) {
                unset($stored[$i]);
                $user->forceFill(['mfa_recovery_codes' => array_values($stored)])->save();
                $this->audit->log('auth.mfa.recovery-code-used', $user, after: ['codes_left' => count($stored)], userId: $user->id);

                return true;
            }
        }

        return false;
    }

    /** The user turns it off (only when their role does not require it). */
    public function disable(User $user, string $code): void
    {
        if ($this->required($user)) {
            throw ValidationException::withMessages(['code' => 'Two-step login is required for your role.']);
        }
        if (! $this->check($user, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is not right.']);
        }

        $this->clear($user);
        $this->audit->log('auth.mfa.disabled', $user, userId: $user->id);
    }

    /** An administrator resets it for someone who lost their phone; they set it up again at next sign-in. */
    public function reset(User $actor, User $user): void
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['user' => 'Use a recovery code to sign in, then set up your new phone yourself.']);
        }
        if ($user->hasRole(Roles::TESSERA_ADMIN) && ! $actor->hasRole(Roles::TESSERA_ADMIN)) {
            throw new AuthorizationException('Only Tessera support can reset a Tessera support account.');
        }

        $this->clear($user);
        $this->audit->log('auth.mfa.reset', $user, userId: $actor->id);
    }

    private function clear(User $user): void
    {
        $user->forceFill(['mfa_secret' => null, 'mfa_enabled_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_step' => null])->save();
    }

    /** @return list<string> 10 letters and digits each */
    private function recoveryCodes(): array
    {
        return array_map(fn () => strtoupper(Str::random(10)), range(1, self::RECOVERY_CODES));
    }
}
