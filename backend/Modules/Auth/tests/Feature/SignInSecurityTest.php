<?php

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Auth\Support\Totp;
use Modules\Authorization\Support\Roles;
use Modules\Settings\Services\SettingsService;
use Tests\TestCase;

/** Two-step login, password changes and expiry, and the back-office idle sign-out. */
class SignInSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function fromFrontend(): static
    {
        $host = config('sanctum.stateful')[0];

        return $this->withHeaders(['Referer' => "http://{$host}/login"]);
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function login(User $user, bool $remember = false): TestResponse
    {
        return $this->fromFrontend()->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'password', 'remember' => $remember]);
    }

    private function setting(string $key, mixed $value): void
    {
        app(SettingsService::class)->set(User::role(Roles::OWNER)->firstOrFail(), $key, 'business', 0, $value);
    }

    public function test_owner_sets_up_two_step_login_at_first_sign_in(): void
    {
        $owner = $this->user(Roles::OWNER);

        $setup = $this->login($owner)->assertOk()->assertJsonPath('data.mfaStep', 'setup')->json('data.setup');
        $this->assertGuest('web');
        $this->assertStringStartsWith('otpauth://totp/', $setup['uri']);

        $this->fromFrontend()->postJson('/api/v1/auth/mfa/setup', ['code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors(['code']);

        $codes = $this->fromFrontend()->postJson('/api/v1/auth/mfa/setup', ['code' => Totp::code($setup['secret'], Totp::step())])
            ->assertOk()
            ->assertJsonPath('data.user.mfaEnabled', true)
            ->json('data.recoveryCodes');

        $this->assertCount(8, $codes);
        $this->assertAuthenticatedAs($owner);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.mfa.enabled', 'user_id' => $owner->id]);
        $this->assertNotSame($setup['secret'], $owner->fresh()->getRawOriginal('mfa_secret'));
    }

    public function test_enrolled_user_needs_a_fresh_code_or_a_recovery_code(): void
    {
        $owner = $this->user(Roles::OWNER);
        $secret = Totp::newSecret();
        $owner->forceFill(['mfa_secret' => $secret, 'mfa_enabled_at' => now(), 'mfa_recovery_codes' => [bcrypt('ABCDE12345')]])->save();

        $this->login($owner)->assertOk()->assertJsonPath('data.mfaStep', 'verify')->assertJsonPath('data.setup', null);
        $this->fromFrontend()->postJson('/api/v1/auth/mfa/verify', ['code' => '123456'])->assertUnprocessable();

        $code = Totp::code($secret, Totp::step());
        $this->fromFrontend()->postJson('/api/v1/auth/mfa/verify', ['code' => $code])->assertOk()->assertJsonPath('data.email', $owner->email);
        $this->assertAuthenticatedAs($owner);

        // The same code cannot be used again.
        $this->fromFrontend()->postJson('/api/v1/auth/logout')->assertOk();
        $this->login($owner)->assertOk();
        $this->fromFrontend()->postJson('/api/v1/auth/mfa/verify', ['code' => $code])->assertUnprocessable();

        // A recovery code works once.
        $this->fromFrontend()->postJson('/api/v1/auth/mfa/verify', ['code' => 'abcde-12345'])->assertOk();
        $this->assertSame([], $owner->fresh()->mfa_recovery_codes);
    }

    public function test_setting_off_skips_two_step_for_owners_but_never_for_tessera_support(): void
    {
        $this->setting('staff.two_step_login', false);

        $this->login($this->user(Roles::OWNER))->assertOk()->assertJsonMissingPath('data.mfaStep');
        $this->fromFrontend()->postJson('/api/v1/auth/logout');
        $this->login($this->user(Roles::TESSERA_ADMIN))->assertOk()->assertJsonPath('data.mfaStep', 'setup');
        $this->login($this->user(Roles::CASHIER))->assertOk()->assertJsonMissingPath('data.mfaStep');
    }

    public function test_admin_resets_someone_elses_two_step_login(): void
    {
        $owner = User::role(Roles::OWNER)->firstOrFail();
        $admin = $this->user(Roles::ADMIN, ['mfa_secret' => Totp::newSecret(), 'mfa_enabled_at' => now()]);
        $support = $this->user(Roles::TESSERA_ADMIN, ['mfa_secret' => Totp::newSecret(), 'mfa_enabled_at' => now()]);

        $this->actingAs($owner)->postJson("/api/v1/auth/users/{$admin->id}/mfa/reset")->assertOk()->assertJsonPath('data.mfaEnabled', false);
        $this->actingAs($owner)->postJson("/api/v1/auth/users/{$support->id}/mfa/reset")->assertForbidden();
        $this->actingAs($owner)->postJson("/api/v1/auth/users/{$owner->id}/mfa/reset")->assertUnprocessable();
        $this->actingAs($this->user(Roles::CASHIER))->postJson("/api/v1/auth/users/{$support->id}/mfa/reset")->assertForbidden();
    }

    public function test_forced_password_change_blocks_the_back_office_until_done(): void
    {
        $cashier = $this->user(Roles::BRANCH_MANAGER, ['must_change_password' => true]);

        $this->login($cashier)->assertOk()->assertJsonPath('data.mustChangePassword', true);
        $this->fromFrontend()->getJson('/api/v1/organisation/branches')->assertForbidden()->assertJsonPath('errors.session.0', 'password_change_required');

        $this->fromFrontend()->postJson('/api/v1/auth/password', ['currentPassword' => 'wrong', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertUnprocessable()->assertJsonValidationErrors(['currentPassword']);
        $this->fromFrontend()->postJson('/api/v1/auth/password', ['currentPassword' => 'password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertOk()->assertJsonPath('data.mustChangePassword', false);

        $this->fromFrontend()->getJson('/api/v1/organisation/branches')->assertOk();
    }

    public function test_passwords_older_than_the_expiry_must_be_changed(): void
    {
        $this->setting('staff.password_expiry_days', 30);
        $manager = $this->user(Roles::BRANCH_MANAGER, ['password_changed_at' => now()->subDays(40)]);

        $this->login($manager)->assertOk()->assertJsonPath('data.mustChangePassword', true);
    }

    public function test_back_office_signs_out_after_the_idle_timeout_unless_kept_signed_in(): void
    {
        $manager = $this->user(Roles::BRANCH_MANAGER);
        $this->login($manager)->assertOk();

        $this->travel(29)->minutes();
        $this->fromFrontend()->getJson('/api/v1/auth/me')->assertOk();
        $this->travel(31)->minutes();
        $this->fromFrontend()->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('errors.session.0', 'idle');

        $this->login($manager, remember: true)->assertOk();
        $this->travel(2)->hours();
        $this->fromFrontend()->getJson('/api/v1/auth/me')->assertOk();
    }
}
