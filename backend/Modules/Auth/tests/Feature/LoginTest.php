<?php

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Sanctum only starts a session for requests from a stateful frontend origin. */
    private function fromFrontend(): static
    {
        $statefulHost = config('sanctum.stateful')[0];

        return $this->withHeaders(['Referer' => "http://{$statefulHost}/login"]);
    }

    public function test_user_can_log_in_and_receives_roles_and_permissions(): void
    {
        $user = User::factory()->create(['email' => 'cashier@tessera.test']);
        $user->assignRole(Roles::CASHIER);

        $response = $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => 'Cashier@Tessera.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'cashier@tessera.test')
            ->assertJsonPath('data.roles', [Roles::CASHIER])
            ->assertJsonMissingPath('data.password');

        $this->assertContains('sales.sell', $response->json('data.permissions'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_wrong_password_is_rejected_with_generic_message_and_audited(): void
    {
        $user = User::factory()->create();

        $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'These credentials do not match our records.');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login.failed', 'user_id' => $user->id]);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(401);

        $this->assertGuest();
    }

    public function test_login_validation_errors_use_the_api_envelope(): void
    {
        $this->fromFrontend()->postJson('/api/v1/auth/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['email', 'password']]);
    }

    public function test_login_is_throttled_after_repeated_attempts(): void
    {
        $payload = ['email' => 'nobody@tessera.test', 'password' => 'x'];

        for ($i = 0; $i < 5; $i++) {
            $this->fromFrontend()->postJson('/api/v1/auth/login', $payload)->assertStatus(401);
        }

        $this->fromFrontend()->postJson('/api/v1/auth/login', $payload)->assertStatus(429);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->fromFrontend()->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertGuest('web');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'user_id' => $user->id]);
    }
}
