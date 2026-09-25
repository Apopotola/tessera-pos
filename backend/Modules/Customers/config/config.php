<?php

return [
    'name' => 'Customers',

    // Requirements proposal: anonymise inactive customers' personal data after 24 months.
    // Financial records (sales, buyer PIN on invoices) are kept for the statutory period.
    'anonymise_after_months' => (int) env('CUSTOMERS_ANONYMISE_AFTER_MONTHS', 24),
];
