<?php

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Organisation\Models\Branch;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function withRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_creates_a_till_only_cashier_and_sets_a_pin(): void
    {
        $admin = $this->withRole(Roles::ADMIN);
        $branchId = Branch::query()->value('id');

        $id = $this->actingAs($admin)->postJson('/api/v1/auth/users', [
            'name' => 'Kiprop Juma',
            'email' => 'Kiprop@Example.com',
            'phone' => '0722 000 111',
            'role' => Roles::CASHIER,
            'branchIds' => [$branchId],
        ])->assertCreated()
            ->assertJsonPath('data.email', 'kiprop@example.com')
            ->assertJsonPath('data.phone', '+254722000111')
            ->assertJsonPath('data.hasPin', false)
            ->json('data.id');

        $this->actingAs($admin)->postJson("/api/v1/auth/users/{$id}/pin", ['pin' => '2580', 'pin_confirmation' => '2580'])
            ->assertOk()
            ->assertJsonPath('data.hasPin', true);

        $user = User::query()->findOrFail($id);
        $this->assertTrue(Hash::check('2580', $user->pin_hash));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'users.pin.set', 'after->pin' => '2580']);
    }

    public function test_guessable_pins_are_rejected(): void
    {
        $admin = $this->withRole(Roles::ADMIN);
        $cashier = $this->withRole(Roles::CASHIER);

        foreach (['1111', '1234', '9876'] as $pin) {
            $this->actingAs($admin)->postJson("/api/v1/auth/users/{$cashier->id}/pin", ['pin' => $pin, 'pin_confirmation' => $pin])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['pin']);
        }
    }

    public function test_admin_cannot_create_an_owner(): void
    {
        $this->actingAs($this->withRole(Roles::ADMIN))->postJson('/api/v1/auth/users', [
            'name' => 'Sneaky', 'email' => 'sneaky@example.com', 'role' => Roles::OWNER,
        ])->assertForbidden();
    }

    public function test_last_owner_cannot_be_demoted(): void
    {
        $owner = User::role(Roles::OWNER)->firstOrFail();

        $this->actingAs($owner)->putJson("/api/v1/auth/users/{$owner->id}", [
            'name' => $owner->name, 'email' => $owner->email, 'role' => Roles::ADMIN,
        ])->assertUnprocessable()->assertJsonValidationErrors(['role']);
    }

    public function test_admin_resets_a_forgotten_password_and_the_person_must_choose_a_new_one(): void
    {
        $admin = $this->withRole(Roles::ADMIN);
        $manager = $this->withRole(Roles::BRANCH_MANAGER);
        $url = "/api/v1/auth/users/{$manager->id}/password";

        $this->actingAs($admin)->postJson($url, ['password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->actingAs($admin)->postJson($url, ['password' => 'Temporary-2026', 'password_confirmation' => 'Temporary-2026'])
            ->assertOk()->assertJsonPath('data.id', $manager->id);

        $manager->refresh();
        $this->assertTrue(Hash::check('Temporary-2026', $manager->password));
        $this->assertTrue($manager->must_change_password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password.reset', 'user_id' => $admin->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.password.reset', 'after->password' => 'Temporary-2026']);

        // Your own password is changed with the current one, not reset.
        $this->actingAs($admin)->postJson("/api/v1/auth/users/{$admin->id}/password", ['password' => 'Temporary-2026', 'password_confirmation' => 'Temporary-2026'])
            ->assertUnprocessable();
        $this->actingAs($this->withRole(Roles::CASHIER))->postJson($url, ['password' => 'Temporary-2026', 'password_confirmation' => 'Temporary-2026'])
            ->assertForbidden();
    }

    public function test_only_an_owner_or_tessera_support_resets_an_owners_password(): void
    {
        $owner = User::role(Roles::OWNER)->firstOrFail();
        $support = $this->withRole(Roles::TESSERA_ADMIN);
        $body = ['password' => 'Temporary-2026', 'password_confirmation' => 'Temporary-2026'];

        $this->actingAs($this->withRole(Roles::ADMIN))->postJson("/api/v1/auth/users/{$owner->id}/password", $body)->assertForbidden();
        $this->actingAs($owner)->postJson("/api/v1/auth/users/{$support->id}/password", $body)->assertForbidden();
        $this->actingAs($support)->postJson("/api/v1/auth/users/{$owner->id}/password", $body)->assertOk();

        $this->assertTrue(Hash::check('Temporary-2026', $owner->fresh()->password));
        $this->assertTrue($owner->fresh()->must_change_password);
    }

    public function test_cashier_cannot_manage_users(): void
    {
        $this->actingAs($this->withRole(Roles::CASHIER))->getJson('/api/v1/auth/users')->assertForbidden();
    }
}
