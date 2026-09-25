<?php

/*
| Sanctum SPA auth uses cookies, so CORS must name explicit origins and allow
| credentials. Local frontend and backend must share the same hostname
| (e.g. both "localhost") or the browser keeps separate cookie jars.
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3010'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'Accept', 'Authorization', 'X-XSRF-TOKEN', 'Idempotency-Key'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
