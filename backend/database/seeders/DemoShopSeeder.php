<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Catalogue\Models\Brand;
use Modules\Catalogue\Models\Category;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Catalogue\Models\TaxRate;
use Modules\Catalogue\Services\CatalogueService;
use Modules\Catalogue\Services\PriceService;
use Modules\Customers\Services\CustomerAccountService;
use Modules\Customers\Services\CustomerService;
use Modules\Expenses\Models\ExpenseCategory;
use Modules\Expenses\Services\ExpenseService;
use Modules\Inventory\Services\AdjustmentService;
use Modules\Inventory\Services\CountService;
use Modules\Inventory\Services\TransferService;
use Modules\Organisation\Models\Branch;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Services\PurchaseOrderService;
use Modules\Purchasing\Services\ReceivingService;
use Modules\Purchasing\Services\SupplierInvoiceService;
use Modules\Purchasing\Services\SupplierReturnService;
use Modules\Purchasing\Services\SupplierService;

/**
 * A full demo shop for showing Tessera to clients, LOCAL only (APP_ENV=local, never production):
 * the sample shop of the user guide ("Karen Wines & Spirits", two branches, two weeks of trading)
 * with a complete wines & spirits catalogue, more suppliers and trade customers, and the open
 * paperwork a demo walks through: orders waiting for approval or delivery, an invoice on query,
 * stock in transit, a breakage and a return waiting for a manager, a stock count, an expense.
 *
 * Run on a fresh database:
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed --class=DemoShowcaseSeeder
 *   php artisan db:seed --class=DemoShopSeeder
 *
 * Everything goes through the real services, so every figure agrees. Brand and product names are
 * what Kenyan shops stock; suppliers and customers are fictional, phone numbers (0712 000 …) and
 * KRA PINs (P000…) are dummies. Prices are typical shelf prices, not quotes.
 */
class DemoShopSeeder extends Seeder
{
    /** Barcodes 2000000001000…: clear of the base demo (…0001) and the user-guide shop (…0500). */
    private int $barcode = 1000;

    private User $owner;

    public function __construct(
        private readonly CatalogueService $catalogue,
        private readonly PriceService $prices,
        private readonly SupplierService $suppliers,
        private readonly CustomerService $customers,
        private readonly CustomerAccountService $accounts,
        private readonly PurchaseOrderService $orders,
        private readonly ReceivingService $receiving,
        private readonly SupplierInvoiceService $invoices,
        private readonly SupplierReturnService $returns,
        private readonly TransferService $transfers,
        private readonly AdjustmentService $adjustments,
        private readonly CountService $counts,
        private readonly ExpenseService $expenses,
    ) {}

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DemoShopSeeder only runs with APP_ENV=local.');

            return;
        }
        if (Supplier::query()->where('name', 'Highlands Wines & Spirits Ltd')->exists()) {
            $this->command?->info('The demo shop is already set up.');

            return;
        }

        $this->owner = User::role(Roles::OWNER)->firstOrFail();
        // Prices entered as the owner apply at once.
        auth()->setUser($this->owner);

        $this->products();
        $this->tradeSuppliers();
        $this->tradeCustomers();

        // Two branches, opening stock for every item above, price history and two weeks of trading.
        $this->call(UserGuideSeeder::class);

        $this->openPaperwork();
        Date::setTestNow();
        $this->command?->info('Demo shop ready: '.ProductVariant::query()->count().' items, '.Supplier::query()->count().' suppliers.');
    }

    /** The shelves: [brand, country, product, category slug, ABV, [[ml, SKU, KES, tot ml?, tot KES?, container?]…]]. */
    private function products(): void
    {
        $catalogue = [
            // Whisky
            ['Johnnie Walker', 'Scotland', 'Johnnie Walker Red Label', 'blended-scotch', 40.0, [[350, 'JWR-350', 1100], [750, 'JWR-750', 2050, 25, 120], [1000, 'JWR-1000', 2600]]],
            ['Chivas Regal', 'Scotland', 'Chivas Regal 12 Years', 'blended-scotch', 40.0, [[750, 'CHV12-750', 4900, 25, 280]]],
            ["Grant's", 'Scotland', "Grant's Triple Wood", 'blended-scotch', 40.0, [[750, 'GRT-750', 1900, 25, 110]]],
            ['Black & White', 'Scotland', 'Black & White', 'blended-scotch', 40.0, [[250, 'BW-250', 550], [750, 'BW-750', 1350]]],
            ['Glenfiddich', 'Scotland', 'Glenfiddich 12 Years', 'single-malt', 40.0, [[750, 'GLF12-750', 6900, 25, 400]]],
            ['The Singleton', 'Scotland', 'The Singleton of Dufftown 12', 'single-malt', 40.0, [[750, 'SGL12-750', 5600]]],
            ['Tullamore D.E.W.', 'Ireland', 'Tullamore D.E.W. Original', 'irish-whiskey', 40.0, [[750, 'TUL-750', 2900, 25, 170]]],
            ["Jack Daniel's", 'USA', "Jack Daniel's Old No. 7", 'bourbon-american', 40.0, [[750, 'JD7-750', 3800, 25, 220], [1000, 'JD7-1000', 4800]]],
            ['Jim Beam', 'USA', 'Jim Beam White', 'bourbon-american', 40.0, [[750, 'JIM-750', 2600]]],
            // Vodka, gin
            ['Absolut', 'Sweden', 'Absolut Vodka', 'vodka', 40.0, [[750, 'ABS-750', 2500, 25, 150]]],
            ['Cîroc', 'France', 'Cîroc Vodka', 'vodka', 40.0, [[750, 'CIR-750', 5500]]],
            ['Chrome', 'Kenya', 'Chrome Vodka', 'vodka', 37.5, [[250, 'CHR-250', 350], [750, 'CHR-750', 900]]],
            ["Gilbey's", 'Kenya', "Gilbey's Special Dry Gin", 'gin', 37.5, [[250, 'GIL-250', 500], [750, 'GIL-750', 1300, 25, 80]]],
            ['Tanqueray', 'United Kingdom', 'Tanqueray London Dry', 'gin', 43.1, [[750, 'TNQ-750', 3300, 25, 190]]],
            ['Beefeater', 'United Kingdom', 'Beefeater London Dry', 'gin', 40.0, [[750, 'BEF-750', 2600]]],
            ['Bombay Sapphire', 'United Kingdom', 'Bombay Sapphire', 'gin', 40.0, [[750, 'BOM-750', 3600]]],
            ["Hendrick's", 'Scotland', "Hendrick's Gin", 'gin', 41.4, [[700, 'HEN-700', 5800]]],
            // Brandy & cognac, rum, tequila
            ['Hennessy', 'France', 'Hennessy V.S', 'brandy-cognac', 40.0, [[700, 'HEN-VS-700', 6500, 25, 380]]],
            ['Martell', 'France', 'Martell V.S. Single Distillery', 'brandy-cognac', 40.0, [[700, 'MAR-VS-700', 6000]]],
            ['Richot', 'Kenya', 'Richot Brandy', 'brandy-cognac', 37.5, [[250, 'RIC-250', 500], [750, 'RIC-750', 1300]]],
            ['Bacardi', 'Puerto Rico', 'Bacardi Carta Blanca', 'rum', 37.5, [[750, 'BAC-750', 2300, 25, 140]]],
            ['Kenya Cane', 'Kenya', 'Kenya Cane', 'rum', 37.5, [[250, 'KC-250', 450], [750, 'KC-750', 1100]]],
            ['Jose Cuervo', 'Mexico', 'Jose Cuervo Especial Gold', 'tequila', 38.0, [[750, 'JCG-750', 3200, 25, 190]]],
            ['Olmeca', 'Mexico', 'Olmeca Silver', 'tequila', 35.0, [[750, 'OLM-750', 2900]]],
            // Liqueurs
            ['Baileys', 'Ireland', 'Baileys Original Irish Cream', 'liqueurs', 17.0, [[375, 'BAI-375', 1600], [750, 'BAI-750', 2900, 25, 170]]],
            ['Amarula', 'South Africa', 'Amarula Cream', 'liqueurs', 17.0, [[750, 'AMA-750', 2400]]],
            ['Jägermeister', 'Germany', 'Jägermeister', 'liqueurs', 35.0, [[700, 'JAG-700', 3200, 25, 200]]],
            ['Kahlúa', 'Mexico', 'Kahlúa Coffee Liqueur', 'liqueurs', 16.0, [[750, 'KAH-750', 2700]]],
            // Wine
            ['Robertson', 'South Africa', 'Robertson Sweet Red', 'red-wine', 7.5, [[750, 'ROB-SR-750', 1050]]],
            ['Robertson', 'South Africa', 'Robertson Natural Sweet White', 'white-wine', 7.5, [[750, 'ROB-SW-750', 1050]]],
            ['Robertson', 'South Africa', 'Robertson Natural Sweet Rosé', 'rose-wine', 7.5, [[750, 'ROB-RO-750', 1050]]],
            ['Casillero del Diablo', 'Chile', 'Casillero del Diablo Cabernet Sauvignon', 'red-wine', 13.5, [[750, 'CDD-CS-750', 1700]]],
            ['Frontera', 'Chile', 'Frontera Merlot', 'red-wine', 12.5, [[750, 'FRO-ME-750', 1100]]],
            ['Caprice', 'Kenya', 'Caprice Sweet Red', 'red-wine', 10.0, [[750, 'CAP-750', 750], [5000, 'CAP-5000', 3300, null, null, 'box']]],
            ['Two Oceans', 'South Africa', 'Two Oceans Sauvignon Blanc', 'white-wine', 12.0, [[750, 'TWO-SB-750', 1150]]],
            ['Martini', 'Italy', 'Martini Asti', 'sparkling-champagne', 7.5, [[750, 'MAR-ASTI-750', 2300]]],
            ['J.C. Le Roux', 'South Africa', 'J.C. Le Roux La Fleurette', 'sparkling-champagne', 7.5, [[750, 'JCL-750', 1800]]],
            ['Moët & Chandon', 'France', 'Moët & Chandon Impérial Brut', 'sparkling-champagne', 12.0, [[750, 'MOET-750', 9500]]],
            ["Taylor's", 'Portugal', "Taylor's Fine Ruby Port", 'fortified-wine', 20.0, [[750, 'TAY-750', 2200]]],
            // Beer, cider, ready-to-drink
            ['White Cap', 'Kenya', 'White Cap Lager', 'beer', 4.2, [[500, 'WC-500', 250]]],
            ['Pilsner', 'Kenya', 'Pilsner Lager', 'beer', 4.7, [[500, 'PIL-500', 230]]],
            ['Balozi', 'Kenya', 'Balozi Lager', 'beer', 4.0, [[500, 'BAL-500', 230]]],
            ['Heineken', 'Netherlands', 'Heineken', 'beer', 5.0, [[330, 'HEI-330', 250, null, null, 'can']]],
            ['Savanna', 'South Africa', 'Savanna Dry Cider', 'cider', 6.0, [[330, 'SAV-330', 280]]],
            ["Hunter's", 'South Africa', "Hunter's Gold Cider", 'cider', 4.5, [[330, 'HUN-330', 260]]],
            ['Smirnoff', 'Kenya', 'Smirnoff Ice Guarana', 'ready-to-drink', 4.5, [[300, 'SMI-GUA-300', 220]]],
            ['Snapp', 'Kenya', 'Snapp Apple', 'ready-to-drink', 5.5, [[330, 'SNP-330', 230]]],
            // Soft drinks, mixers, water, energy (0% ABV: sold at any hour)
            ['Sprite', 'Kenya', 'Sprite', 'soft-drinks', 0.0, [[500, 'SPR-500', 90]]],
            ['Fanta', 'Kenya', 'Fanta Orange', 'soft-drinks', 0.0, [[500, 'FAN-500', 90]]],
            ['Stoney', 'Kenya', 'Stoney Tangawizi', 'soft-drinks', 0.0, [[500, 'STO-500', 90]]],
            ['Krest', 'Kenya', 'Krest Bitter Lemon', 'mixers', 0.0, [[500, 'KRE-500', 100]]],
            ['Schweppes', 'Kenya', 'Schweppes Soda Water', 'mixers', 0.0, [[500, 'SCH-SODA-500', 100]]],
            ['Dasani', 'Kenya', 'Dasani Water', 'water', 0.0, [[500, 'DAS-500', 60], [1000, 'DAS-1000', 90]]],
            ['Red Bull', 'Austria', 'Red Bull Energy Drink', 'energy-drinks', 0.0, [[250, 'RB-250', 250, null, null, 'can']]],
            ['Monster', 'USA', 'Monster Energy', 'energy-drinks', 0.0, [[500, 'MON-500', 300, null, null, 'can']]],
            // Accessories
            ['Polar Ice', 'Kenya', 'Polar Ice cubes 2 kg', 'accessories', 0.0, [[2000, 'ICE-2KG', 200, null, null, 'pouch']]],
        ];

        $vat = TaxRate::query()->where('code', 'B')->value('id');
        foreach ($catalogue as [$brandName, $country, $name, $categorySlug, $abv, $variants]) {
            $brand = Brand::query()->firstOrCreate(['name' => $brandName], ['country' => $country]);
            ['product' => $product] = $this->catalogue->createProduct([
                'brandId' => $brand->id,
                'categoryId' => Category::query()->where('slug', $categorySlug)->value('id'),
                'name' => $name,
                'abv' => $abv,
                'variants' => array_map(fn (array $v) => [
                    'volumeMl' => $v[0],
                    'container' => $v[5] ?? 'bottle',
                    'sku' => $v[1],
                    'taxRateId' => $vat,
                    // Placeholder so the mock eTIMS signs demo sales; real KRA item class codes replace it.
                    'etimsItemClassCode' => 'DEMO0001',
                    'totMl' => $v[3] ?? null,
                    'barcodes' => [sprintf('2000000%06d', $this->barcode++)],
                    'retailPriceCents' => $v[2] * 100,
                ], $variants),
            ], $this->owner);

            foreach ($variants as $v) {
                $variant = $product->variants()->where('sku', $v[1])->firstOrFail();
                // Sell by tot: the price of one tot (25 ml) poured from an open bottle.
                if (isset($v[4])) {
                    $this->prices->request($variant, ['tier' => 'tot', 'priceCents' => $v[4] * 100, 'reason' => 'Opening tot price'], $this->owner);
                }
                // Trade customers (bars, hotels) buy beer, wine and soft drinks about 8% cheaper.
                if (in_array($categorySlug, ['beer', 'cider', 'ready-to-drink', 'red-wine', 'white-wine', 'rose-wine', 'soft-drinks', 'mixers', 'water'], true)) {
                    $this->prices->request($variant, ['tier' => 'wholesale', 'priceCents' => (int) (round($v[2] * 0.92) * 100), 'reason' => 'Trade price'], $this->owner);
                }
                // Beer by the crate: scanning the crate barcode sells 24 bottles at once.
                if ($categorySlug === 'beer') {
                    $this->catalogue->createPack($variant, ['name' => 'Crate of 24', 'units' => 24, 'barcode' => sprintf('2100000%06d', $this->barcode++)]);
                }
            }
        }
    }

    /** Four more suppliers alongside the two in the base demo (fictional). */
    private function tradeSuppliers(): void
    {
        foreach ([
            ['Highlands Wines & Spirits Ltd', 'P000000011A', 'Janet Wambui', '0712 000 211', 'orders@highlandswines.test', 30],
            ['Savanna Breweries Depot', 'P000000012B', 'Brian Otieno', '0712 000 212', 'depot@savannabreweries.test', 7],
            ['Nairobi Soft Drinks Distributors', 'P000000013C', 'Faith Mutua', '0712 000 213', null, 14],
            ['Premium Imports Kenya Ltd', 'P000000014D', 'Samuel Kariuki', '0712 000 214', 'sales@premiumimports.test', 45],
        ] as [$name, $pin, $contact, $phone, $email, $terms]) {
            $this->suppliers->create([
                'name' => $name, 'kraPin' => $pin, 'contactPerson' => $contact, 'phone' => $phone, 'email' => $email,
                'address' => 'Industrial Area, Nairobi', 'paymentTermsDays' => $terms,
            ], $this->owner);
        }
    }

    /** Three more trade customers (fictional), two of them on account. */
    private function tradeCustomers(): void
    {
        foreach ([
            ['Kilimani Hotel & Suites', 'P000000021E', true, 'Rose Achieng', '0712 000 121', 10_000_000],
            ['Ngong Road Social Club', 'P000000022F', true, 'Daniel Kamau', '0712 000 122', 5_000_000],
            ['Lavington Events Co.', 'P000000023G', false, 'Mercy Njeri', '0712 000 123', null],
        ] as [$name, $pin, $wholesale, $contact, $phone, $limit]) {
            $customer = $this->customers->create([
                'name' => $name, 'kraPin' => $pin, 'isWholesale' => $wholesale, 'contactName' => $contact, 'phone' => $phone, 'isActive' => true,
            ], $this->owner);
            if ($limit) {
                $this->accounts->setCredit($customer, $limit, 30, $this->owner);
            }
        }
    }

    /** Everything a demo opens on the screens: waiting for someone, on its way, or under query. */
    private function openPaperwork(): void
    {
        $now = CarbonImmutable::now();
        $manager = User::query()->where('email', 'manager@tessera.test')->firstOrFail();
        $storekeeper = User::query()->where('email', 'njeri@tessera.test')->firstOrFail();
        $accountant = User::query()->where('email', 'accountant@tessera.test')->firstOrFail();
        $karen = Branch::query()->where('code', 'MAIN')->firstOrFail();
        $westlands = Branch::query()->where('code', 'WLD')->firstOrFail();
        $location = fn (Branch $branch, string $code) => $branch->locations()->where('code', $code)->firstOrFail();
        $sku = fn (string $sku) => ProductVariant::query()->where('sku', $sku)->value('id');
        $supplier = fn (string $name) => Supplier::query()->where('name', $name)->value('id');
        $line = fn (string $code, int $qty, int $kes) => ['variantId' => $sku($code), 'quantity' => $qty, 'unitCostCents' => $kes * 100];

        // 1. Beer and soft drinks for the weekend: drafted by the owner, waiting for the manager.
        Date::setTestNow($now->subHours(3));
        $this->orders->create(['supplierId' => $supplier('Savanna Breweries Depot'), 'locationId' => $location($karen, 'STORE')->id,
            'note' => 'Weekend restock', 'lines' => [$line('TUS-500', 96, 175), $line('WC-500', 72, 180), $line('GUI-500', 48, 200), $line('SAV-330', 48, 195)]], $this->owner);

        // 2. Wines ordered, approved and sent: the delivery is expected this week.
        Date::setTestNow($now->subDays(2)->setTime(10, 0));
        $wines = $this->orders->create(['supplierId' => $supplier('Highlands Wines & Spirits Ltd'), 'locationId' => $location($karen, 'STORE')->id,
            'note' => 'Monthly wine order', 'lines' => [$line('ROB-SR-750', 24, 720), $line('CDD-CS-750', 12, 1150), $line('MAR-ASTI-750', 12, 1600), $line('4TH-750', 24, 700)]], $this->owner);
        $this->orders->approve($wines, $manager);
        $this->orders->markSent($wines->fresh(), $this->owner);

        // 3. Cognac and champagne received at Westlands; the supplier's invoice is KES 3,000 too high, so it is on query.
        Date::setTestNow($now->subDays(4)->setTime(9, 30));
        $premium = $this->orders->create(['supplierId' => $supplier('Premium Imports Kenya Ltd'), 'locationId' => $location($westlands, 'STORE')->id,
            'note' => 'Festive stock', 'lines' => [$line('HEN-VS-700', 6, 4600), $line('MOET-750', 6, 6800), $line('GLF12-750', 6, 4900)]], $this->owner);
        $this->orders->approve($premium, $manager);
        Date::setTestNow($now->subDays(3)->setTime(15, 0));
        $premium = $premium->fresh()->load('lines');
        $grn = $this->receiving->receive($premium, $storekeeper, $premium->lines->map(fn ($l) => ['lineId' => $l->id, 'received' => $l->quantity_ordered, 'damaged' => 0])->all(), 'PIK-DN-7781', null);
        $subtotal = (int) $premium->lines->sum(fn ($l) => $l->quantity_ordered * $l->unit_cost_cents) + 300000;
        $vat = (int) round($subtotal * 0.16);
        $this->invoices->record([
            'supplierId' => $premium->supplier_id, 'invoiceNumber' => 'PIK-INV-2291', 'invoiceDate' => $now->subDays(3)->toDateString(),
            'grnIds' => [$grn->id], 'subtotalCents' => $subtotal, 'vatCents' => $vat, 'totalCents' => $subtotal + $vat, 'note' => 'Check the Moët unit price',
        ], $accountant);

        // 4. Westlands is short of whisky: Karen's store sends some over; it is on the way.
        Date::setTestNow($now->subHours(5));
        $transfer = $this->transfers->request(['fromLocationId' => $location($karen, 'STORE')->id, 'toLocationId' => $location($westlands, 'STORE')->id,
            'note' => 'Weekend at Westlands', 'lines' => [['variantId' => $sku('JWB-750'), 'quantity' => 12], ['variantId' => $sku('JAM-750'), 'quantity' => 12], ['variantId' => $sku('JWR-750'), 'quantity' => 12]]], $storekeeper);
        $this->transfers->approve($transfer, $manager);
        Date::setTestNow($now->subHours(4));
        $this->transfers->dispatch($transfer->fresh()->load('lines'), $storekeeper, []);

        // 5. Two bottles broken while stocking the shelves: waiting for the manager's approval.
        Date::setTestNow($now->subHours(2));
        $this->adjustments->create(['locationId' => $location($karen, 'FLOOR')->id, 'type' => 'breakage', 'reason' => 'Dropped while restocking the wine shelf',
            'lines' => [['variantId' => $sku('ROB-SR-750'), 'quantity' => 1], ['variantId' => $sku('CAP-750'), 'quantity' => 1]]], $storekeeper);

        // 6. A carton of flat tonic going back to the supplier: waiting for approval.
        Date::setTestNow($now->subHours(6));
        $this->returns->create(['supplierId' => $supplier('Nairobi Soft Drinks Distributors'), 'locationId' => $location($karen, 'STORE')->id,
            'reason' => 'Flat tonic water, batch 2214', 'lines' => [['variantId' => $sku('SCH-TONIC-500'), 'quantity' => 12]]], $storekeeper);

        // 7. Tonight's stock count of the Westlands shop floor has been started.
        Date::setTestNow($now->subHours(1));
        $this->counts->create(['locationId' => $location($westlands, 'FLOOR')->id, 'note' => 'Monthly count'], $storekeeper);

        // 8. Generator fuel paid from petty cash, waiting for the manager.
        $category = ExpenseCategory::query()->orderBy('id')->value('id');
        if ($category) {
            Date::setTestNow($now->subHours(4));
            $this->expenses->request([
                'branchId' => $karen->id, 'categoryId' => $category, 'amountCents' => 350000, 'paidFrom' => 'petty_cash',
                'payee' => 'Total Energies Karen', 'description' => 'Generator fuel during the power cut', 'reference' => null, 'spentOn' => $now->toDateString(),
            ], $accountant);
        }
    }
}
