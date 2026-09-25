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

    public function test_cashier_cannot_manage_users(): void
    {
        $this->actingAs($this->withRole(Roles::CASHIER))->getJson('/api/v1/auth/users')->assertForbidden();
    }
}
