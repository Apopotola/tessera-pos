<?php

namespace Modules\Catalogue\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Catalogue\Models\Category;
use Modules\Catalogue\Models\TaxRate;

class CatalogueDatabaseSeeder extends Seeder
{
    /**
     * Reference data for every environment. Idempotent.
     * Demo products are seeded separately, for local development only.
     */
    public function run(): void
    {
        $this->seedTaxRates();
        $this->seedCategories();

        if (app()->environment('local')) {
            $this->call(CatalogueDemoSeeder::class);
        }
    }

    /**
     * eTIMS tax types A–E as commonly documented for Kenya.
     * REQUIRES VALIDATION against the KRA OSCU/VSCU v2.0 specification before go-live.
     */
    private function seedTaxRates(): void
    {
        foreach ([
            ['code' => 'A', 'name' => 'Exempt', 'rate_bp' => 0],
            ['code' => 'B', 'name' => 'VAT 16%', 'rate_bp' => 1600],
            ['code' => 'C', 'name' => 'Zero-rated', 'rate_bp' => 0],
            ['code' => 'D', 'name' => 'Non-VAT', 'rate_bp' => 0],
            ['code' => 'E', 'name' => 'VAT 8%', 'rate_bp' => 800],
        ] as $rate) {
            TaxRate::query()->firstOrCreate(['code' => $rate['code']], $rate);
        }
    }

    private function seedCategories(): void
    {
        $tree = [
            'Wine' => ['Red wine', 'White wine', 'Rosé wine', 'Sparkling & Champagne', 'Fortified wine'],
            'Whisky' => ['Blended Scotch', 'Single malt', 'Irish whiskey', 'Bourbon & American'],
            'Vodka' => [],
            'Gin' => [],
            'Brandy & Cognac' => [],
            'Rum' => [],
            'Tequila' => [],
            'Liqueurs' => [],
            'Beer & Cider' => ['Beer', 'Cider', 'Ready-to-drink'],
            'Soft drinks & Mixers' => ['Mixers', 'Soft drinks', 'Water', 'Energy drinks'],
            'Accessories' => [],
        ];

        $order = 0;
        foreach ($tree as $parentName => $children) {
            $parent = Category::query()->firstOrCreate(
                ['slug' => Str::slug($parentName)],
                ['name' => $parentName, 'sort_order' => $order++],
            );

            foreach ($children as $i => $childName) {
                Category::query()->firstOrCreate(
                    ['slug' => Str::slug($childName)],
                    ['name' => $childName, 'parent_id' => $parent->id, 'sort_order' => $i],
                );
            }
        }
    }
}
