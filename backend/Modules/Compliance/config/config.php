<?php

return [
    'name' => 'Compliance',

    'etims' => [
        /*
         * fake     — mock KRA for demos and development: signs invoices like KRA would.
         * disabled — nothing is sent; every invoice stays "pending" (never claimed as compliant).
         * A real VSCU/OSCU driver implements Contracts\EtimsGateway once the KRA v2.0 spec
         * and sandbox access (etims-sbx.kra.go.ke) are in hand.
         */
        'driver' => env('ETIMS_DRIVER', 'disabled'),

        // Control-unit and branch identifiers issued by KRA at device initialisation.
        'scu_id' => env('ETIMS_SCU_ID', 'KRACU0100000001'),
        'branch_code' => env('ETIMS_BRANCH_CODE', '00'),

        // Mock KRA: pretend KRA cannot be reached, to demo queuing and retries.
        'fake_offline' => (bool) env('ETIMS_FAKE_OFFLINE', false),

        // Retry back-off: 1, 2, 4 … minutes, capped (requirements: capped at 30 min).
        'max_backoff_minutes' => 30,
        // Dashboard alert when an invoice has waited longer than this.
        'pending_alert_minutes' => 60,
    ],
];
