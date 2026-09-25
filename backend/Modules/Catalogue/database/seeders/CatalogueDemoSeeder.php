<?php

namespace Modules\Catalogue\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\Brand;
use Modules\Catalogue\Models\Category;
use Modules\Catalogue\Models\Product;
use Modules\Catalogue\Models\TaxRate;
use Modules\Catalogue\Services\CatalogueService;

/**
 * Local-only sample catalogue so screens have data. Prices are illustrative KES,
 * not market prices. Barcodes use the 200-prefix (in-store range) to avoid real EANs.
 */
class CatalogueDemoSeeder extends Seeder
{
    public function run(CatalogueService $catalogue): void
    {
        $owner = User::role(Roles::OWNER)->first();
        if (! $owner || Product::query()->exists()) {
            return;
        }

        // Seeded prices are written as the Owner, who applies prices immediately.
        auth()->setUser($owner);
        $vat = TaxRate::query()->where('code', 'B')->value('id');

        $products = [
            ['Johnnie Walker', 'Scotland', 'Johnnie Walker Black Label', 'blended-scotch', 40.0, [[200, 'JWB-200', 1350], [375, 'JWB-375', 2400], [750, 'JWB-750', 4800], [1000, 'JWB-1000', 6100]]],
            ['Jameson', 'Ireland', 'Jameson Irish Whiskey', 'irish-whiskey', 40.0, [[350, 'JAM-350', 1650], [750, 'JAM-750', 3100], [1000, 'JAM-1000', 3900]]],
            ['Smirnoff', 'United Kingdom', 'Smirnoff Red Vodka', 'vodka', 37.5, [[250, 'SMR-250', 650], [750, 'SMR-750', 1650]]],
            ["Gordon's", 'United Kingdom', "Gordon's London Dry Gin", 'gin', 37.5, [[750, 'GOR-750', 2300]]],
            ['4th Street', 'South Africa', '4th Street Sweet Red', 'red-wine', 7.5, [[750, '4TH-750', 1050], [5000, '4TH-5000', 4900]]],
            ['Tusker', 'Kenya', 'Tusker Lager', 'beer', 4.2, [[500, 'TUS-500', 250]]],
        ];

        $n = 1;
        foreach ($products as [$brandName, $country, $name, $categorySlug, $abv, $variants]) {
            $brand = Brand::query()->firstOrCreate(['name' => $brandName], ['country' => $country]);

            $catalogue->createProduct([
                'brandId' => $brand->id,
                'categoryId' => Category::query()->where('slug', $categorySlug)->value('id'),
                'name' => $name,
                'abv' => $abv,
                'variants' => array_map(function (array $v) use ($vat, &$n) {
                    [$ml, $sku, $kes] = $v;

                    return [
                        'volumeMl' => $ml,
                        'container' => 'bottle',
                        'sku' => $sku,
                        'taxRateId' => $vat,
                        'barcodes' => [sprintf('2000000%06d', $n++)],
                        'retailPriceCents' => $kes * 100,
                    ];
                }, $variants),
            ], $owner);
        }
    }
}
