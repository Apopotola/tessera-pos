<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;

class InventoryDatabaseSeeder extends Seeder
{
    /** Inventory has no reference data; demo stock is seeded in local development only. */
    public function run(): void
    {
        if (app()->environment('local')) {
            $this->call(InventoryDemoSeeder::class);
        }
    }
}
