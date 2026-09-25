<?php

namespace Modules\Customers\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Customers\Models\Customer;

class CustomersDatabaseSeeder extends Seeder
{
    /**
     * Local demo only: two fictional business customers (made-up names and PINs).
     */
    public function run(): void
    {
        if (! app()->environment('local') || Customer::query()->exists()) {
            return;
        }

        $owner = User::role(Roles::OWNER)->first();
        if (! $owner) {
            return;
        }

        foreach ([
            ['name' => 'Demo Lounge & Grill', 'kra_pin' => 'P000111222A', 'is_wholesale' => true, 'contact_name' => 'Demo Buyer'],
            ['name' => 'Demo Events Ltd', 'kra_pin' => 'P000333444B', 'is_wholesale' => false, 'contact_name' => null],
        ] as $row) {
            $customer = new Customer($row);
            $customer->created_by = $owner->id;
            $customer->save();
        }
    }
}
