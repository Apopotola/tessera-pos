<?php

namespace Modules\Customers\Console;

use Illuminate\Console\Command;
use Modules\Customers\Services\CustomerService;

/** Retention: anonymise customers inactive for customers.anonymise_after_months. Scheduled daily. */
class AnonymiseInactiveCustomers extends Command
{
    protected $signature = 'customers:anonymise-inactive';

    protected $description = 'Remove personal data of customers with no activity for the retention period (sales are kept)';

    public function handle(CustomerService $customers): int
    {
        $this->info('Anonymised '.$customers->anonymiseInactive().' inactive customer(s).');

        return self::SUCCESS;
    }
}
