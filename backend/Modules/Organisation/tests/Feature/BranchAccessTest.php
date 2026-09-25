<?php

namespace Modules\Organisation\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Organisation\Models\Branch;
use Tests\TestCase;

class BranchAccessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function secondBranch(): Branch
    {
        return Branch::query()->create([
            'business_id' => Branch::query()->value('business_id'),
            'code' => 'WL',
            'name' => 'Westlands',
        ]);
    }

    public function test_cashier_only_sees_assigned_branches(): void
    {
        $westlands = $this->secondBranch();
        $cashier = User::factory()->create();
        $cashier->assignRole(Roles::CASHIER);
        $cashier->branches()->attach($westlands);

        $this->actingAs($cashier)->getJson('/api/v1/organisation/branches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'WL');
    }

    public function test_owner_sees_all_active_branches(): void
    {
        $this->secondBranch();
        $owner = User::factory()->create();
        $owner->assignRole(Roles::OWNER);

        $this->actingAs($owner)->getJson('/api/v1/organisation/branches')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
