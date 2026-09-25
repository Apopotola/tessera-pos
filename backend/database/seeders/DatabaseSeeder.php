<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Authorization\Database\Seeders\AuthorizationDatabaseSeeder;
use Modules\Catalogue\Database\Seeders\CatalogueDatabaseSeeder;
use Modules\Customers\Database\Seeders\CustomersDatabaseSeeder;
use Modules\Inventory\Database\Seeders\InventoryDatabaseSeeder;
use Modules\Organisation\Database\Seeders\OrganisationDatabaseSeeder;
use Modules\Purchasing\Database\Seeders\PurchasingDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Module seeders in dependency order. Each is idempotent.
     */
    public function run(): void
    {
        $this->call([
            OrganisationDatabaseSeeder::class,
            AuthorizationDatabaseSeeder::class,
            AuthDatabaseSeeder::class,
            CatalogueDatabaseSeeder::class,
            InventoryDatabaseSeeder::class,
            PurchasingDatabaseSeeder::class,
            CustomersDatabaseSeeder::class,
        ]);
    }
}
