<?php

return [
    'name' => 'Sales',

    // Largest discount a cashier may give without a manager's PIN, as % of the line value.
    'discount_limit_percent' => (int) env('TESSERA_DISCOUNT_LIMIT_PERCENT', 5),

    // How long a manager's PIN approval stays valid for the cashier to use (seconds).
    'approval_ttl_seconds' => 300,

    // Days after a sale within which a return is accepted (business policy).
    // Offline till: queued sales older than this are refused on sync (REQUIRES VALIDATION with KRA/VSCU rules).
    'offline_max_hours' => (int) env('SALES_OFFLINE_MAX_HOURS', 72),
    'return_window_days' => (int) env('TESSERA_RETURN_WINDOW_DAYS', 7),

    // Removing a scanned line worth more than this (cents) needs a manager's PIN; smaller voids are only logged.
    'void_approval_threshold_cents' => (int) env('TESSERA_VOID_APPROVAL_THRESHOLD_CENTS', 500000),

    // Printed at the bottom of every receipt.
    'receipt_footer' => env('TESSERA_RECEIPT_FOOTER', 'Thank you for shopping with us.'),
];
