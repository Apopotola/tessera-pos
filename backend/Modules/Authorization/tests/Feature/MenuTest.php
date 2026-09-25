<?php

namespace Modules\Authorization\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Tests\TestCase;

class MenuTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** @return list<string> */
    private function menuKeysFor(string $role): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        $tree = $this->actingAs($user)->getJson('/api/v1/authorization/menus')
            ->assertOk()
            ->json('data');

        $keys = [];
        $walk = function (array $items) use (&$walk, &$keys) {
            foreach ($items as $item) {
                $keys[] = $item['key'];
                $walk($item['children']);
            }
        };
        $walk($tree);

        return $keys;
    }

    public function test_cashier_sees_the_till_but_not_administration(): void
    {
        $keys = $this->menuKeysFor(Roles::CASHIER);

        $this->assertContains('pos', $keys);
        $this->assertNotContains('admin', $keys);
        $this->assertNotContains('admin.audit', $keys);
        $this->assertNotContains('reports', $keys);
    }

    public function test_owner_sees_every_menu_item(): void
    {
        $keys = $this->menuKeysFor(Roles::OWNER);

        $this->assertContains('admin.audit', $keys);
        $this->assertContains('compliance.etims', $keys);
    }

    public function test_menu_items_expose_a_view_type_for_the_frontend_registry(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(Roles::OWNER);

        $first = Arr::first($this->actingAs($owner)->getJson('/api/v1/authorization/menus')->json('data'));

        $this->assertSame(['key', 'title', 'icon', 'viewType', 'path', 'children'], array_keys($first));
        $this->assertSame('dashboard', $first['viewType']);
    }
}
