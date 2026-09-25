<?php

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Organisation\Models\Branch;

/**
 * Local development only: staff for the till screen. Demo PINs are listed in README.md.
 */
class AuthDemoSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->where('code', 'MAIN')->first();
        if (! $branch) {
            return;
        }

        $staff = [
            ['Wanjiru Mwangi', 'manager@tessera.test', Roles::BRANCH_MANAGER, '4826', 'password'],
            ['Otieno Kamau', 'otieno@tessera.test', Roles::CASHIER, '2580', null],
            ['Amina Hassan', 'amina@tessera.test', Roles::CASHIER, '3691', null],
            ['Njeri Wambui', 'njeri@tessera.test', Roles::STOREKEEPER, null, 'password'],
        ];

        foreach ($staff as [$name, $email, $role, $pin, $password]) {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => $password ?? Str::random(40),
            ]);
            $user->syncRoles([$role]);
            $user->branches()->syncWithoutDetaching([$branch->id]);

            if ($pin && ! $user->pin_hash) {
                $user->forceFill(['pin_hash' => Hash::make($pin), 'pin_set_at' => now()])->save();
            }
        }

        $owner = User::role(Roles::OWNER)->first();
        if ($owner && ! $owner->pin_hash) {
            $owner->forceFill(['pin_hash' => Hash::make('1470'), 'pin_set_at' => now()])->save();
        }
    }
}
