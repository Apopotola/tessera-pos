<?php

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use RuntimeException;

class AuthDatabaseSeeder extends Seeder
{
    /**
     * Creates the first Owner account from TESSERA_OWNER_EMAIL / TESSERA_OWNER_PASSWORD.
     * Outside local/testing both variables are required; nothing is hard-coded for production.
     */
    public function run(): void
    {
        $email = config('tessera-auth.owner.email');
        $password = config('tessera-auth.owner.password');

        if (! $email || ! $password) {
            if (! app()->environment(['local', 'testing'])) {
                throw new RuntimeException('Set TESSERA_OWNER_EMAIL and TESSERA_OWNER_PASSWORD before seeding.');
            }

            $email = 'owner@tessera.test';
            $password = 'password';
        }

        $owner = User::query()->firstOrCreate(
            ['email' => mb_strtolower($email)],
            [
                'name' => 'Business Owner',
                'password' => $password,
                'must_change_password' => ! app()->environment(['local', 'testing']),
            ],
        );

        $owner->syncRoles([Roles::OWNER]);
    }
}
