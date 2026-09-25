<?php

namespace Modules\Organisation\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Organisation\Enums\LocationType;
use Modules\Organisation\Models\Branch;
use Modules\Organisation\Models\Business;

class OrganisationDatabaseSeeder extends Seeder
{
    /**
     * Seeds a single business with one branch and its default stock locations.
     * Idempotent: safe to run repeatedly.
     */
    public function run(): void
    {
        $business = Business::query()->firstOrCreate(
            ['name' => config('organisation.default_business_name')],
        );

        $branch = Branch::query()->firstOrCreate(
            ['code' => 'MAIN'],
            ['business_id' => $business->id, 'name' => 'Main Branch'],
        );

        foreach ([
            ['code' => 'FLOOR', 'name' => 'Shop floor', 'type' => LocationType::ShopFloor],
            ['code' => 'STORE', 'name' => 'Back store', 'type' => LocationType::Store],
            ['code' => 'QUAR', 'name' => 'Quarantine (damaged/returns)', 'type' => LocationType::Quarantine],
        ] as $location) {
            $branch->locations()->firstOrCreate(
                ['code' => $location['code']],
                [
                    'name' => $location['name'],
                    'type' => $location['type'],
                    'is_sellable' => $location['type']->isSellable(),
                ],
            );
        }
    }
}
