<?php

return [
    'name' => 'Auth',

    // First Owner account created by AuthDatabaseSeeder.
    'owner' => [
        'email' => env('TESSERA_OWNER_EMAIL'),
        'password' => env('TESSERA_OWNER_PASSWORD'),
    ],

    // Login attempts per minute per email + IP.
    'login_rate_limit' => (int) env('TESSERA_LOGIN_RATE_LIMIT', 5),
];
