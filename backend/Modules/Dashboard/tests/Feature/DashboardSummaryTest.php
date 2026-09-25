<?php

namespace Modules\Dashboard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function as(string $role)
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $this->actingAs($user)->getJson('/api/v1/dashboard/summary')->assertOk();
    }

    public function test_owner_sees_every_section(): void
    {
        $this->as(Roles::OWNER)
            ->assertJsonPath('data.pendingPriceChanges', 0)
            ->assertJsonPath('data.tills.total', 0)
            ->assertJsonPath('data.openShifts', [])
            ->assertJsonStructure(['data' => ['branches', 'catalogue' => ['activeProducts', 'activeVariants'], 'staff' => ['active', 'cashiersWithoutPin']]]);
    }

    public function test_sections_without_permission_are_null(): void
    {
        $this->as(Roles::STOREKEEPER)
            ->assertJsonPath('data.pendingPriceChanges', null)
            ->assertJsonPath('data.openShifts', null)
            ->assertJsonPath('data.staff', null)
            ->assertJsonPath('data.tills', null);
    }
}
