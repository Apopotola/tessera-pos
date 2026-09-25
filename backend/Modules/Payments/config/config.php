<?php

return [
    'name' => 'Payments',

    'mpesa' => [
        /*
         * daraja — live Safaricom Daraja (sandbox or production).
         * fake   — local development and tests: STK requests succeed after a few seconds.
         * manual — no integration yet: cashiers type the M-PESA code and it is marked unverified.
         */
        'driver' => env('MPESA_DRIVER', 'manual'),
        'environment' => env('MPESA_ENVIRONMENT', 'sandbox'), // sandbox | production

        'consumer_key' => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),

        // M-PESA Express (STK Push): business shortcode + passkey from the Daraja portal.
        'shortcode' => env('MPESA_SHORTCODE'),
        'passkey' => env('MPESA_PASSKEY'),
        // Till (Buy Goods) number the customer pays; for Paybill it is the shortcode itself.
        'party_b' => env('MPESA_PARTY_B'),
        'transaction_type' => env('MPESA_TRANSACTION_TYPE', 'CustomerBuyGoodsOnline'), // or CustomerPayBillOnline
        'account_reference' => env('MPESA_ACCOUNT_REFERENCE', 'Tessera'),

        // Public HTTPS base URL Safaricom can reach (e.g. an ngrok URL in development).
        'callback_base_url' => env('MPESA_CALLBACK_BASE_URL', env('APP_URL')),
        // Secret path segment on callback URLs: Daraja does not sign its callbacks.
        'callback_token' => env('MPESA_CALLBACK_TOKEN'),

        // Till waits this long for the customer's PIN before asking Daraja for the status.
        'stk_timeout_seconds' => (int) env('MPESA_STK_TIMEOUT', 60),
        // Customer-initiated payments offered to the till for matching.
        'unallocated_window_hours' => 24,
        // Fake driver: seconds before a request counts as paid by the "customer".
        'fake_delay_seconds' => (int) env('MPESA_FAKE_DELAY', 5),
    ],
];
