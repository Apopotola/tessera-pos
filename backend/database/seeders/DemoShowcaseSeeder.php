<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\Promotion;
use Modules\Catalogue\Services\PromotionService;
use Modules\Compliance\Models\Licence;
use Modules\Customers\Models\Customer;
use Modules\Customers\Services\CustomerAccountService;
use Modules\Notifications\Services\ScheduledAlerts;
use Modules\Organisation\Models\Branch;
use Modules\Settings\Services\SettingsService;

/**
 * Demo showcase for LOCAL demos only (never production):
 *   php artisan db:seed --class=DemoShowcaseSeeder
 *
 * - Two-step login off for owners and admins in this demo database (Tessera support keeps it),
 *   so the owner can sign in without a phone. Switch it back on in Settings → Staff.
 * - Demo PINs reset to the ones in the README.
 * - Customer credit on, one account customer; two approved promotions; licences, one due soon.
 * Sales, payments, expenses and supplier invoices are made through the app itself.
 * Safe to run again.
 */
class DemoShowcaseSeeder extends Seeder
{
    public function run(SettingsService $settings, CustomerAccountService $accounts, PromotionService $promotions, ScheduledAlerts $alerts): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DemoShowcaseSeeder only runs with APP_ENV=local.');

            return;
        }

        $owner = User::role(Roles::OWNER)->firstOrFail();
        $support = User::role(Roles::TESSERA_ADMIN)->firstOrFail();
        $branch = Branch::query()->where('code', 'MAIN')->firstOrFail();

        // Settings for the demo.
        foreach (['staff.two_step_login' => false, 'payments.customer_credit' => 'on', 'payments.credit_approval' => 'any'] as $key => $value) {
            $settings->set($support, $key, 'business', 0, $value, 'edit');
        }

        // Demo PINs as documented (they may have been changed while testing).
        foreach (['owner@tessera.test' => '1470', 'manager@tessera.test' => '4826', 'otieno@tessera.test' => '2580', 'amina@tessera.test' => '3691'] as $email => $pin) {
            User::query()->where('email', $email)->first()?->forceFill(['pin_hash' => Hash::make($pin), 'pin_set_at' => now()])->save();
        }

        // An account customer: KES 30,000 limit, 30 days.
        $lounge = Customer::query()->where('name', 'Demo Lounge & Grill')->first();
        if ($lounge && $lounge->credit_limit_cents === null) {
            $accounts->setCredit($lounge, 3_000_000, 30, $owner);
        }

        // Promotions (the owner's own are approved as saved).
        $wine = DB::table('categories')->where('name', 'Wine')->value('id');
        $jameson = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.name', 'Jameson Irish Whiskey')->where('v.volume_ml', 750)->value('v.id');
        $running = fn (string $name) => Promotion::query()->where('name', $name)->where('status', Promotion::ACTIVE)->exists();
        if ($wine && ! $running('Wine weekend: buy 2, 10% off')) {
            $promotions->request([
                'name' => 'Wine weekend: buy 2, 10% off', 'discountType' => 'percent', 'discountValue' => 1000, 'minQuantity' => 2,
                'startsOn' => now()->toDateString(), 'endsOn' => now()->addMonths(2)->toDateString(), 'categoryIds' => [$wine],
            ], $owner);
        }
        if ($jameson && ! $running('Jameson happy hour')) {
            $promotions->request([
                'name' => 'Jameson happy hour', 'discountType' => 'amount', 'discountValue' => 30000, 'minQuantity' => 1,
                'startsOn' => now()->toDateString(), 'endsOn' => now()->addMonths(2)->toDateString(),
                'timeFrom' => '17:00', 'timeTo' => '20:00', 'variantIds' => [$jameson],
            ], $owner);
        }

        // Licences: the liquor licence runs out in 20 days (alert), the permit is fine.
        $licences = [
            ['liquor_licence', 'Nairobi County liquor licence', 'NCC/LL/2026/0457', now()->addDays(20), true],
            ['business_permit', 'Single business permit', 'SBP/2026/118832', now()->addMonths(9), false],
        ];
        foreach ($licences as [$type, $name, $number, $expires, $print]) {
            Licence::query()->firstOrCreate(['branch_id' => $branch->id, 'type' => $type, 'number' => $number], [
                'name' => $name, 'issuer' => 'Nairobi City County', 'issued_on' => $expires->copy()->subYear(),
                'expires_on' => $expires, 'print_on_receipt' => $print, 'created_by' => $owner->id,
            ]);
        }
        $alerts->licenceExpiry();

        $this->command?->info('Demo showcase ready: owner signs in without a code; see README → Demo walkthrough.');
    }
}
