<?php

namespace Modules\Catalogue\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\Category;
use Modules\Catalogue\Models\TaxRate;
use Tests\TestCase;

abstract class CatalogueTestCase extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function owner(): User
    {
        return $this->userWithRole(Roles::OWNER);
    }

    /** @return array<string, mixed> */
    protected function variantPayload(int $ml, string $sku, array $overrides = []): array
    {
        return [
            'volumeMl' => $ml,
            'container' => 'bottle',
            'sku' => $sku,
            'taxRateId' => TaxRate::query()->where('code', 'B')->value('id'),
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    protected function productPayload(array $variants, array $overrides = []): array
    {
        return [
            'categoryId' => Category::query()->where('slug', 'blended-scotch')->value('id'),
            'name' => 'Test Black Label',
            'abv' => 40,
            'variants' => $variants,
            ...$overrides,
        ];
    }

    protected function createProductAs(User $user, array $variants, array $overrides = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/catalogue/products', $this->productPayload($variants, $overrides));
    }
}
