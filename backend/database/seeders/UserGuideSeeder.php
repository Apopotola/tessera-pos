<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\Brand;
use Modules\Catalogue\Models\Category;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Catalogue\Models\TaxRate;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Catalogue\Services\CatalogueService;
use Modules\Customers\Models\Customer;
use Modules\Customers\Services\CustomerAccountService;
use Modules\Expenses\Models\ExpenseCategory;
use Modules\Expenses\Services\ExpenseService;
use Modules\Inventory\Services\AdjustmentService;
use Modules\Inventory\Services\ReorderService;
use Modules\Notifications\Models\Alert;
use Modules\Notifications\Services\ScheduledAlerts;
use Modules\Organisation\Enums\LocationType;
use Modules\Organisation\Models\Branch;
use Modules\Organisation\Models\Till;
use Modules\Organisation\Services\TillDeviceService;
use Modules\Payments\Services\C2bService;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Services\PurchaseOrderService;
use Modules\Purchasing\Services\ReceivingService;
use Modules\Purchasing\Services\SupplierAccountService;
use Modules\Purchasing\Services\SupplierInvoiceService;
use Modules\Sales\Services\SaleService;
use Modules\Sales\Services\ShiftService;
use Modules\Settings\Services\SettingsService;

/**
 * Sample shop for the client user guide's screenshots, LOCAL only (never production):
 * "Karen Wines & Spirits" with a Karen and a Westlands branch, two weeks of trading and
 * everyday paperwork, all made through the real services so every number agrees.
 *
 * Run on a fresh database after the demo seeders:
 *   php artisan migrate:fresh --seed && php artisan db:seed --class=DemoShowcaseSeeder
 *   php artisan db:seed --class=UserGuideSeeder
 *
 * Names are Kenyan but fictional; phone numbers (0712 000 …) and KRA PINs (P000…) are dummies.
 * Random choices use a fixed seed, so the data is the same every time.
 */
class UserGuideSeeder extends Seeder
{
    private const FLOAT_CENTS = 500000;

    private User $owner;

    private User $manager;

    private User $storekeeper;

    private User $accountant;

    /** What the lounge has bought on account so far, kept inside its KES 30,000 limit. */
    private int $onAccountCents = 0;

    /** The real time when the seeder started (the clock is moved back while writing history). */
    private CarbonImmutable $realNow;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly CatalogueService $catalogue,
        private readonly AdjustmentService $adjustments,
        private readonly TillDeviceService $tills,
        private readonly ShiftService $shifts,
        private readonly SaleService $sales,
        private readonly C2bService $c2b,
        private readonly CustomerAccountService $accounts,
        private readonly ExpenseService $expenses,
        private readonly PurchaseOrderService $orders,
        private readonly ReceivingService $receiving,
        private readonly SupplierInvoiceService $invoices,
        private readonly SupplierAccountService $supplierAccounts,
        private readonly ReorderService $reorder,
        private readonly ScheduledAlerts $alerts,
    ) {}

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('UserGuideSeeder only runs with APP_ENV=local.');

            return;
        }
        if (Branch::query()->where('code', 'WLD')->exists()) {
            $this->command?->info('The user-guide shop is already set up.');

            return;
        }
        mt_srand(2026);
        $this->realNow = CarbonImmutable::now('Africa/Nairobi');

        $this->owner = User::role(Roles::OWNER)->firstOrFail();
        $this->manager = User::query()->where('email', 'manager@tessera.test')->firstOrFail();
        $this->storekeeper = User::query()->where('email', 'njeri@tessera.test')->firstOrFail();
        $this->accountant = User::query()->where('email', 'accountant@tessera.test')->firstOrFail();

        $karen = $this->identity();
        $westlands = $this->westlands();
        $this->products();
        $this->backdatePrices();
        $this->openingStock($karen, 40);
        $this->openingStock($westlands, 30);

        $tills = [
            [$karen, $this->till($karen, 'Till 1', 'Main counter'), User::query()->where('email', 'otieno@tessera.test')->firstOrFail()],
            [$westlands, $this->till($westlands, 'Till 1', 'Front counter'), User::query()->where('email', 'amina@tessera.test')->firstOrFail()],
        ];

        $today = $this->realNow->startOfDay();
        for ($daysAgo = 13; $daysAgo >= 1; $daysAgo--) {
            foreach ($tills as [$branch, $till, $cashier]) {
                $this->tradingDay($branch, $till, $cashier, $today->subDays($daysAgo), closeShift: true);
            }
        }
        $this->paperwork($karen, $today);
        // This morning: the Karen till is open and has already sold a little.
        [$branch, $till, $cashier] = $tills[0];
        $this->tradingDay($branch, $till, $cashier, $today, closeShift: false);
        $this->lowStock($karen);
        Date::setTestNow();
        $this->alerts->lowStockDigest();

        $this->command?->info('Karen Wines & Spirits is ready for the user guide screenshots.');
    }

    /** Business name, main branch, people, customers and suppliers renamed for the sample shop. */
    private function identity(): Branch
    {
        $support = User::role(Roles::TESSERA_ADMIN)->firstOrFail();
        foreach ([
            'business.legal_name' => 'Karen Wines & Spirits',
            'business.address' => 'Karen Road, Karen, Nairobi',
            'business.phone' => '0712 000 000',
            'business.email' => 'info@karenwines.test',
        ] as $key => $value) {
            $this->settings->set($support, $key, 'business', 0, $value, 'edit');
        }

        $karen = Branch::query()->where('code', 'MAIN')->firstOrFail();
        $karen->forceFill(['name' => 'Karen'])->save();
        // The licence alert from the demo showcase named the old branch: raise it again.
        Alert::query()->where('type', Alert::LICENCE_EXPIRY)->delete();
        $this->alerts->licenceExpiry();
        $this->owner->forceFill(['name' => 'David Kiprop'])->save();

        $customers = [
            'Demo Lounge & Grill' => ['name' => 'Karen Country Lounge', 'contact_name' => 'Grace Wairimu', 'phone' => '0712 000 101', 'email' => 'orders@karenlounge.test'],
            'Demo Events Ltd' => ['name' => 'Langata Events Ltd', 'contact_name' => 'Peter Ochieng', 'phone' => '0712 000 102', 'email' => null],
        ];
        foreach ($customers as $old => $new) {
            Customer::query()->where('name', $old)->first()?->forceFill($new)->save();
        }
        $bar = new Customer(['name' => 'Westlands Sports Bar', 'kra_pin' => 'P000000003C', 'is_wholesale' => true, 'contact_name' => 'Kevin Mwangi', 'phone' => '0712 000 103']);
        $bar->created_by = $this->owner->id;
        $bar->save();

        $suppliers = [
            'Rift Valley Spirits Distributors (demo)' => ['name' => 'Rift Valley Spirits Distributors', 'contact_person' => 'Mary Njoroge', 'phone' => '0712 000 201'],
            'Coast Beverages Ltd (demo)' => ['name' => 'Coast Beverages Ltd', 'contact_person' => 'Hassan Omar', 'phone' => '0712 000 202'],
        ];
        foreach ($suppliers as $old => $new) {
            Supplier::query()->where('name', $old)->first()?->forceFill($new)->save();
        }

        return $karen;
    }

    private function westlands(): Branch
    {
        $karen = Branch::query()->where('code', 'MAIN')->firstOrFail();
        $branch = Branch::query()->create(['business_id' => $karen->business_id, 'code' => 'WLD', 'name' => 'Westlands']);
        foreach ([
            ['code' => 'FLOOR', 'name' => 'Shop floor', 'type' => LocationType::ShopFloor],
            ['code' => 'STORE', 'name' => 'Back store', 'type' => LocationType::Store],
            ['code' => 'QUAR', 'name' => 'Quarantine (damaged/returns)', 'type' => LocationType::Quarantine],
        ] as $location) {
            $branch->locations()->create([...$location, 'is_sellable' => $location['type']->isSellable()]);
        }
        // The branch manager, storekeeper and one cashier work at both shops.
        foreach (['manager@tessera.test', 'njeri@tessera.test', 'amina@tessera.test'] as $email) {
            User::query()->where('email', $email)->first()?->branches()->syncWithoutDetaching([$branch->id]);
        }

        return $branch;
    }

    /** Soft drinks and mixers (0% ABV, sold at any hour) and a few more wines and spirits. */
    private function products(): void
    {
        auth()->setUser($this->owner);
        $vat = TaxRate::query()->where('code', 'B')->value('id');
        $n = 500;
        $products = [
            ['Nederburg', 'South Africa', 'Nederburg Cabernet Sauvignon', 'red-wine', 13.5, [[750, 'NED-CS-750', 1450]]],
            ['Four Cousins', 'South Africa', 'Four Cousins Sweet Rosé', 'rose-wine', 8.0, [[750, 'FC-ROSE-750', 1150], [1500, 'FC-ROSE-1500', 2100]]],
            ['Drostdy-Hof', 'South Africa', 'Drostdy-Hof Chardonnay', 'white-wine', 12.5, [[750, 'DH-CHAR-750', 1250]]],
            ['Viceroy', 'South Africa', 'Viceroy Brandy', 'brandy-cognac', 43.0, [[250, 'VIC-250', 750], [750, 'VIC-750', 1950]]],
            ['Captain Morgan', 'Jamaica', 'Captain Morgan Spiced Gold', 'rum', 35.0, [[750, 'CM-750', 2200]]],
            ['Guinness', 'Ireland', 'Guinness Foreign Extra Stout', 'beer', 6.5, [[500, 'GUI-500', 280]]],
            ['Coca-Cola', 'Kenya', 'Coca-Cola', 'soft-drinks', 0.0, [[500, 'COKE-500', 90]]],
            ['Schweppes', 'Kenya', 'Schweppes Tonic Water', 'mixers', 0.0, [[500, 'SCH-TONIC-500', 110]]],
            ['Keringet', 'Kenya', 'Keringet Still Water', 'water', 0.0, [[1000, 'KER-1000', 100]]],
        ];
        foreach ($products as [$brandName, $country, $name, $categorySlug, $abv, $variants]) {
            $brand = Brand::query()->firstOrCreate(['name' => $brandName], ['country' => $country]);
            $this->catalogue->createProduct([
                'brandId' => $brand->id,
                'categoryId' => Category::query()->where('slug', $categorySlug)->value('id'),
                'name' => $name,
                'abv' => $abv,
                'variants' => array_map(function (array $v) use ($vat, &$n) {
                    [$ml, $sku, $kes] = $v;

                    return [
                        'volumeMl' => $ml, 'container' => 'bottle', 'sku' => $sku, 'taxRateId' => $vat,
                        'etimsItemClassCode' => 'DEMO0001', 'barcodes' => [sprintf('2000000%06d', $n++)], 'retailPriceCents' => $kes * 100,
                    ];
                }, $variants),
            ], $this->owner);
        }
    }

    /**
     * The price list is append-only and the demo prices start today, so the same prices are added
     * again as approved rows dated 30 days back: the two weeks of history have prices to sell at.
     */
    private function backdatePrices(): void
    {
        $since = CarbonImmutable::now()->subDays(30);
        VariantPrice::query()->where('status', 'approved')->get()->each(fn (VariantPrice $p) => VariantPrice::query()->create([
            'variant_id' => $p->variant_id, 'branch_id' => $p->branch_id, 'tier' => $p->tier, 'price_cents' => $p->price_cents,
            'min_price_cents' => $p->min_price_cents, 'effective_from' => $since, 'status' => 'approved', 'requested_by' => $this->owner->id,
        ]));
    }

    /** Opening stock on the shop floor and in the back store (approved by the branch manager). */
    private function openingStock(Branch $branch, int $floorQty): void
    {
        $variants = ProductVariant::query()->with('prices')->where('is_active', true)->get();
        foreach ([['FLOOR', $floorQty], ['STORE', $floorQty * 2]] as [$code, $qty]) {
            $location = $branch->locations()->where('code', $code)->firstOrFail();
            $adjustment = $this->adjustments->create([
                'locationId' => $location->id,
                'type' => 'opening',
                'reason' => 'Opening stock',
                'lines' => $variants->map(fn (ProductVariant $v) => [
                    'variantId' => $v->id,
                    'quantity' => $v->volume_ml > 1000 ? intdiv($qty, 3) : $qty,
                    'unitCostCents' => (int) round(($v->prices->max('price_cents') ?: 10000) * 0.68),
                ])->values()->all(),
            ], $this->storekeeper);
            $this->adjustments->approve($adjustment, $this->manager, 'Opening stock checked');
        }
    }

    private function till(Branch $branch, string $name, string $description): Till
    {
        return $this->tills->pair(['branchId' => $branch->id, 'name' => $name, 'description' => $description, 'defaultFloatCents' => self::FLOAT_CENTS], $this->owner)['till'];
    }

    /**
     * One day at one till: open at 10:00 with the float, sell through the day, and (for past days)
     * count the drawer at 21:30 and have the manager sign the cash-up off the next morning.
     */
    private function tradingDay(Branch $branch, Till $till, User $cashier, CarbonImmutable $day, bool $closeShift): void
    {
        // Today's shift opened an hour ago at the latest (the seeder may run before 10:00).
        $opens = $closeShift ? $day->setTime(10, 0) : $day->setTime(10, 0)->min($this->realNow->subHour());
        Date::setTestNow($opens);
        $shift = $this->shifts->start($till, $cashier, self::FLOAT_CENTS)['shift'];

        $weekend = $day->isFriday() || $day->isSaturday();
        $count = $closeShift ? mt_rand(8, 12) + ($weekend ? 6 : 0) : 5;
        $lastHour = $closeShift ? 21 : max(10, min(21, (int) $this->realNow->format('H')));
        $variants = ProductVariant::query()->where('is_active', true)->pluck('id')->all();
        $times = collect(range(1, $count))->map(fn () => $day->setTime(mt_rand(10, max(10, $lastHour)), mt_rand(0, 59)))
            ->filter(fn ($t) => $closeShift || $t->lte($this->realNow))->sort()->values();

        foreach ($times as $at) {
            Date::setTestNow($at);
            $lines = collect(array_rand(array_flip($variants), mt_rand(1, 3)) ?: [])->map(fn ($id) => ['variantId' => $id, 'unit' => 'bottle', 'quantity' => mt_rand(1, 2)])->values()->all();
            // Today's few sales: mostly cash, so the day's cash takings are clear on the dashboard.
            $roll = $closeShift ? mt_rand(1, 100) : [60, 20, 70, 95, 80][$times->search($at) % 5];
            $customer = $branch->code === 'MAIN' && $roll > 95 ? Customer::query()->where('name', 'Karen Country Lounge')->first() : null;
            $total = $this->total($till, $cashier, $lines, $at, (bool) $customer?->is_wholesale);
            if ($customer && $this->onAccountCents + $total > 2_500_000) {
                $customer = null;
                $total = $this->total($till, $cashier, $lines, $at, false);
            }
            $tender = match (true) {
                $customer !== null => ['method' => 'credit', 'amountCents' => $total],
                $roll <= 50 => $this->mpesa($total, $at),
                $roll <= 88 => ['method' => 'cash', 'amountCents' => (int) (ceil($total / 50000) * 50000)],
                default => ['method' => 'card', 'amountCents' => $total, 'reference' => (string) mt_rand(100000, 999999), 'cardLast4' => (string) mt_rand(1000, 9999)],
            };
            try {
                $this->sales->complete($till, $cashier, [
                    'clientId' => (string) Str::uuid(), 'lines' => $lines, 'tenders' => [$tender], 'customerId' => $customer?->id,
                ]);
                $this->onAccountCents += $customer ? $total : 0;
            } catch (ValidationException) {
                // An item ran short on the shop floor: this customer went without.
            }
        }

        if (! $closeShift) {
            return;
        }
        Date::setTestNow($day->setTime(21, 30));
        $expected = $this->shifts->cashInDrawer($shift->fresh());
        // One evening at Westlands the drawer is KES 200 short, so the variance screens have an example.
        $short = $branch->code === 'WLD' && $day->isTuesday() ? 20000 : 0;
        $closed = $this->shifts->close($shift->fresh(), $cashier, $expected - $short, null);
        if ($short) {
            $this->shifts->explainVariance($closed, $cashier, 'Gave a customer KES 200 too much change during the evening rush.');
        }
        Date::setTestNow($day->addDay()->setTime(9, 15));
        $this->shifts->review($closed->fresh(), $this->manager, $short ? 'Discussed with Amina; count change twice.' : null);
    }

    /** A few popular items at Karen end up at or below their reorder level, for the low-stock screens. */
    private function lowStock(Branch $karen): void
    {
        foreach (['JAM-750', 'SMR-750', 'TUS-500', 'GOR-750'] as $sku) {
            $variantId = ProductVariant::query()->where('sku', $sku)->value('id');
            $onHand = (int) DB::table('stock_balances')->where(['branch_id' => $karen->id, 'variant_id' => $variantId])->sum('quantity');
            $this->reorder->set($this->manager, $karen->id, $variantId, $onHand + 6, 24);
        }
    }

    /** The sale's total as the till would price it (promotions included). */
    private function total(Till $till, User $cashier, array $lines, CarbonImmutable $at, bool $wholesale): int
    {
        $priced = (fn () => $this->priceLines($till, $cashier, $lines, $wholesale, $at))->call($this->sales);

        return array_sum(array_column($priced, 'line_total_cents'));
    }

    /** A demo M-PESA payment to the till, confirmed like Safaricom would. */
    private function mpesa(int $amountCents, CarbonImmutable $at): array
    {
        $receipt = 'S'.strtoupper(Str::random(9));
        $confirmation = $this->c2b->record([
            'TransID' => $receipt, 'TransAmount' => number_format($amountCents / 100, 2, '.', ''), 'TransTime' => $at->format('YmdHis'),
            'MSISDN' => '254712000000', 'FirstName' => 'M-PESA', 'LastName' => 'CUSTOMER', 'BillRefNumber' => 'TILL',
        ]);

        return ['method' => 'mpesa', 'amountCents' => $amountCents, 'confirmationId' => $confirmation->id, 'reference' => $receipt];
    }

    /** A week ago: stock ordered, received and billed; a payment from the lounge; a few expenses. */
    private function paperwork(Branch $karen, CarbonImmutable $today): void
    {
        $day = $today->subDays(7);

        Date::setTestNow($day->setTime(9, 0));
        $order = PurchaseOrder::query()->where('status', 'draft')->first();
        if ($order) {
            $this->orders->approve($order, $this->manager);
            Date::setTestNow($day->setTime(14, 30));
            $order->refresh()->load('lines');
            $grn = $this->receiving->receive($order, $this->storekeeper, $order->lines->map(fn ($l) => ['lineId' => $l->id, 'received' => $l->quantity_ordered, 'damaged' => 0])->all(), 'DN-44120', null);
            Date::setTestNow($day->setTime(16, 0));
            $subtotal = (int) $order->lines->sum(fn ($l) => $l->quantity_ordered * $l->unit_cost_cents);
            $vat = (int) round($subtotal * 0.16);
            $this->invoices->record([
                'supplierId' => $order->supplier_id, 'invoiceNumber' => 'RVSD-10433', 'invoiceDate' => $day->toDateString(),
                'grnIds' => [$grn->id], 'subtotalCents' => $subtotal, 'vatCents' => $vat, 'totalCents' => $subtotal + $vat, 'note' => null,
            ], $this->accountant);
            Date::setTestNow($today->subDays(2)->setTime(11, 0));
            $this->supplierAccounts->recordPayment(Supplier::query()->findOrFail($order->supplier_id), [
                'amountCents' => (int) (round(($subtotal + $vat) / 2 / 100) * 100), 'method' => 'bank', 'reference' => 'EFT-230918',
                'paidOn' => $today->subDays(2)->toDateString(), 'note' => 'Part payment',
            ], $this->accountant);
        }

        Date::setTestNow($today->subDays(3)->setTime(12, 0));
        $lounge = Customer::query()->where('name', 'Karen Country Lounge')->first();
        if ($lounge && $this->accounts->balance($lounge) > 0) {
            $this->accounts->receivePayment($lounge, [
                'amountCents' => min($this->accounts->balance($lounge), 300000), 'method' => 'mpesa', 'reference' => 'SKA7Q2M1PX',
                'branchId' => $karen->id, 'receivedAt' => $today->subDays(3)->setTime(12, 0)->toIso8601String(), 'note' => null,
            ], $this->accountant);
        }

        $categories = ExpenseCategory::query()->pluck('id', 'name');
        foreach ([
            [5, 'Electricity & water', 485000, 'bank', 'Electricity supplier', 'Electricity tokens, Karen shop'],
            [4, 'Cleaning & supplies', 150000, 'petty_cash', 'Sparkle Cleaners', 'Weekly cleaning'],
            [2, 'Transport & delivery', 80000, 'mpesa', 'Boda rider', 'Delivery to Langata Events'],
        ] as [$ago, $category, $cents, $from, $payee, $what]) {
            $categoryId = $categories[$category] ?? $categories->first();
            if (! $categoryId) {
                break;
            }
            Date::setTestNow($today->subDays($ago)->setTime(15, 0));
            $expense = $this->expenses->request([
                'branchId' => $karen->id, 'categoryId' => $categoryId, 'amountCents' => $cents, 'paidFrom' => $from,
                'payee' => $payee, 'description' => $what, 'reference' => null, 'spentOn' => $today->subDays($ago)->toDateString(),
            ], $this->accountant);
            $this->expenses->approve($expense, $this->manager, null);
        }
    }
}
