<?php

namespace Modules\Sales\Services;

use App\Services\DocumentNumberService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Enums\PriceTier;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Catalogue\Services\PriceResolver;
use Modules\Catalogue\Services\PromotionService;
use Modules\Catalogue\Support\PromotionEngine;
use Modules\Compliance\Services\EtimsOutbox;
use Modules\Customers\Models\Customer;
use Modules\Customers\Services\CustomerAccountService;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\StockEntry;
use Modules\Inventory\Services\StockLedger;
use Modules\Organisation\Enums\LocationType;
use Modules\Organisation\Models\Location;
use Modules\Organisation\Models\Till;
use Modules\Payments\Services\MpesaAllocator;
use Modules\Sales\Events\SaleCompleted;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleLine;
use Modules\Sales\Models\SaleTender;
use Modules\Sales\Models\Shift;
use Modules\Sales\Support\Money;

/**
 * Completes a till sale in one transaction: price every line from the approved price
 * list, enforce discount/override approvals, post stock out of the shop floor (sales
 * never block on stock — negatives are caught by counts), record tenders, and mark the
 * sale "eTIMS pending" for the Compliance module. Idempotent on the till's clientId.
 */
class SaleService
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly StockLedger $ledger,
        private readonly TillApprovalService $approvals,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly OpenBottleService $bottles,
        private readonly MpesaAllocator $mpesa,
        private readonly EtimsOutbox $etims,
        private readonly TillPolicy $policy,
        private readonly CustomerAccountService $accounts,
        private readonly PromotionService $promotions,
    ) {}

    /**
     * @param  array{clientId: string, customerPin?: string|null, stockApprovalToken?: string|null, lines: list<array{variantId: int, unit?: string|null, quantity: int, unitPriceCents?: int|null, discountCents?: int|null, approvalToken?: string|null}>, tenders: list<array{method: string, amountCents: int, reference?: string|null, cardLast4?: string|null}>}  $data
     * @return array{sale: Sale, replayed: bool}
     */
    public function complete(Till $till, User $cashier, array $data): array
    {
        if (! $cashier->can(Permissions::SALES_SELL)) {
            throw new AuthorizationException;
        }

        if ($existing = Sale::query()->where('client_id', $data['clientId'])->first()) {
            if ($existing->till_id !== $till->id) {
                throw ValidationException::withMessages(['clientId' => 'This sale reference belongs to another till.']);
            }

            return ['sale' => $existing, 'replayed' => true];
        }

        $shift = $this->openShift($till, $cashier);
        $location = $this->salesLocation($till);
        $customer = $this->customer($data['customerId'] ?? null);
        // Offline till: the sale happened earlier; price it as it was then.
        $occurredAt = $this->occurredAt($data['occurredAt'] ?? null, $shift);
        $offline = isset($data['occurredAt']);
        if ($offline) {
            $this->assertOfflineTenders($data['tenders']);
        }
        $lines = $this->priceLines($till, $cashier, $data['lines'], (bool) $customer?->is_wholesale, $occurredAt);
        $total = array_sum(array_column($lines, 'line_total_cents'));
        ['tenders' => $tenders, 'rounding' => $rounding] = $this->settle($till, $data['tenders'], $total);
        $creditApprovedBy = $this->checkCredit($till, $cashier, $customer, $tenders, $data['creditApprovalToken'] ?? null);
        $etims = $this->policy->etimsEnabled($till);

        return DB::transaction(function () use ($till, $cashier, $data, $shift, $location, $lines, $total, $tenders, $rounding, $customer, $occurredAt, $offline, $etims, $creditApprovedBy) {
            // Offline sales already happened: they are always recorded and the next count catches any gap.
            $stockApprovedBy = $offline ? null : $this->checkStock($till, $cashier, $location, $lines, $data['stockApprovalToken'] ?? null);
            // Reserve the id first so the ledger can reference the sale and give us exact costs.
            $saleId = (int) DB::selectOne("SELECT nextval('sales_id_seq') AS id")->id;
            $number = $this->numbers->next($till->branch, Sale::NUMBER_PREFIX, $this->policy->invoicePrefix($till));

            // Bottles leave the shelf through the ledger; tots pour from the open bottle.
            $bottleLines = array_values(array_filter($lines, fn ($l) => $l['unit'] === SaleLine::UNIT_BOTTLE));
            $movements = $bottleLines ? $this->ledger->post(
                array_map(fn ($l) => new StockEntry($location, $l['variant_id'], -$l['quantity'], MovementType::Sale), $bottleLines),
                Sale::DOCUMENT_TYPE, $saleId, $number, $cashier->id, allowNegative: true,
            ) : [];
            $costs = collect($movements)->mapWithKeys(fn ($m) => [$m->variant_id => (int) $m->unit_cost_cents]);

            foreach ($lines as $i => $line) {
                $lines[$i]['unit_cost_cents'] = $line['unit'] === SaleLine::UNIT_TOT
                    ? Money::proportion($this->bottles->pour($location, $line['variant'], $line['quantity'] * $line['tot_ml'], $cashier, $saleId), 1, $line['quantity'])
                    : $costs[$line['variant_id']];
                unset($lines[$i]['variant']);
            }

            // forceCreate: the id was reserved from the sequence above.
            $sale = Sale::query()->forceCreate([
                'id' => $saleId,
                'client_id' => $data['clientId'],
                'number' => $number,
                'branch_id' => $till->branch_id,
                'till_id' => $till->id,
                'shift_id' => $shift->id,
                'location_id' => $location->id,
                'user_id' => $cashier->id,
                'customer_id' => $customer?->id,
                // The buyer PIN printed on the eTIMS invoice: typed at the till, or the customer's own.
                'customer_pin' => isset($data['customerPin']) ? mb_strtoupper($data['customerPin']) : $customer?->kra_pin,
                'subtotal_cents' => array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price_cents'], $lines)),
                // The cashier's discounts and promotions.
                'discount_cents' => array_sum(array_column($lines, 'discount_cents')) + array_sum(array_column($lines, 'promotion_discount_cents')),
                'total_cents' => $total,
                'rounding_cents' => $rounding,
                'vat_cents' => array_sum(array_column($lines, 'vat_cents')),
                'cost_cents' => array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_cost_cents'], $lines)),
                'status' => 'completed',
                // Branches outside eTIMS (Settings → Integrations) send nothing to KRA.
                'etims_status' => $etims ? 'pending' : Sale::ETIMS_NOT_REQUIRED,
                'completed_at' => $occurredAt,
                'captured_offline' => $offline,
            ]);

            foreach ($lines as $line) {
                $sale->lines()->create($line);
            }
            foreach ($tenders as $tender) {
                $confirmationId = $tender['confirmation_id'] ?? null;
                unset($tender['confirmation_id']);
                $row = $sale->tenders()->create([...$tender, 'shift_id' => $shift->id]);
                if ($confirmationId) {
                    $this->mpesa->allocate($confirmationId, $row, $cashier);
                }
            }

            // Transactional outbox: the sale cannot commit without its eTIMS job.
            if ($etims) {
                $this->etims->queueSale($sale);
            }

            $this->audit->log('sales.sale.completed', $sale, after: [
                'number' => $number,
                'total_cents' => $total,
                'tenders' => array_map(fn ($t) => [$t['method'], $t['amount_cents']], $tenders),
                'approved_lines' => count(array_filter($lines, fn ($l) => $l['approved_by'] !== null)),
                'captured_offline' => $offline,
                'rounding_cents' => $rounding,
                'below_zero_approved_by' => $stockApprovedBy,
                'credit_approved_by' => $creditApprovedBy,
            ], userId: $cashier->id, branchId: $till->branch_id, reference: "till:{$till->id}");

            SaleCompleted::dispatch($sale->id);

            return ['sale' => $sale, 'replayed' => false];
        });
    }

    /**
     * When an offline sale really happened. It must fall inside the cashier's open shift,
     * not in the future, and within the configured offline window.
     */
    private function occurredAt(?string $value, Shift $shift): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        if ($value === null) {
            return $now;
        }

        $at = CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
        $maxHours = (int) config('sales.offline_max_hours', 72);

        if ($at->gt($now->addMinutes(5))) {
            throw ValidationException::withMessages(['occurredAt' => 'The sale time is in the future. Check the till clock.']);
        }
        if ($at->lt($now->subHours($maxHours))) {
            throw ValidationException::withMessages(['occurredAt' => "Offline sales must be sent within {$maxHours} hours."]);
        }
        if ($at->lt(CarbonImmutable::parse($shift->opened_at)->subMinutes(5))) {
            throw ValidationException::withMessages(['occurredAt' => 'This sale was made before the current shift started.']);
        }

        return $at;
    }

    /** Offline there is no Safaricom confirmation: cash and card only. @param list<array<string, mixed>> $tenders */
    private function assertOfflineTenders(array $tenders): void
    {
        foreach ($tenders as $tender) {
            if (! in_array($tender['method'], [SaleTender::CASH, SaleTender::CARD], true)) {
                throw ValidationException::withMessages(['tenders' => 'Offline sales can only be paid in cash or by card.']);
            }
        }
    }

    private function customer(?int $id): ?Customer
    {
        if (! $id) {
            return null;
        }
        $customer = Customer::query()->find($id);
        if (! $customer || ! $customer->is_active || $customer->isAnonymised()) {
            throw ValidationException::withMessages(['customerId' => 'This customer cannot be used on a sale.']);
        }

        return $customer;
    }

    /**
     * A sale on the customer's credit account (Settings → Payments → Customer credit): only for
     * account customers; a manager approves when it goes over the limit, or every time when the
     * owner says so. Returns the approving manager. Offline tills never take credit.
     *
     * @param  list<array<string, mixed>>  $tenders
     */
    private function checkCredit(Till $till, User $cashier, ?Customer $customer, array $tenders, ?string $approvalToken): ?int
    {
        $onAccount = array_sum(array_map(fn ($t) => $t['method'] === SaleTender::CREDIT ? $t['amount_cents'] : 0, $tenders));
        if ($onAccount === 0) {
            return null;
        }
        if (! $customer) {
            throw ValidationException::withMessages(['tenders' => 'Pick the account customer before putting a sale on account.']);
        }

        $check = $this->accounts->creditCheck($customer, $onAccount);
        if (! $check['overLimit'] && ! $this->policy->creditNeedsManager($till)) {
            return null;
        }
        if ($approvalToken === null) {
            $why = $check['overLimit']
                ? "{$customer->name} would owe KES ".number_format(($check['balanceCents'] + $onAccount) / 100, 2).', over the KES '.number_format($check['limitCents'] / 100, 2).' limit.'
                : 'Sales on account need a manager.';
            throw ValidationException::withMessages(['creditApproval' => "{$why} A manager must approve."]);
        }

        return $this->approvals->consume($approvalToken, 'credit', $till, $cashier);
    }

    /**
     * Selling more bottles than the shop floor holds (Settings → Products and stock):
     * allowed, allowed with a manager's PIN, or blocked. Returns the approving manager.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function checkStock(Till $till, User $cashier, Location $location, array $lines, ?string $approvalToken): ?int
    {
        $rule = $this->policy->belowZero($till);
        if ($rule === 'allow') {
            return null;
        }

        $wanted = [];
        foreach ($lines as $line) {
            if ($line['unit'] === SaleLine::UNIT_BOTTLE) {
                $wanted[$line['variant_id']] = ($wanted[$line['variant_id']] ?? 0) + $line['quantity'];
            }
        }
        $short = array_keys(array_filter($wanted, fn ($qty, $variantId) => $this->ledger->onHand($location, $variantId) < $qty, ARRAY_FILTER_USE_BOTH));
        if ($short === []) {
            return null;
        }

        $names = ProductVariant::query()->with('product')->findMany($short)->map(fn ($v) => $v->display_name)->implode(', ');
        if ($rule === 'block') {
            throw ValidationException::withMessages(['stock' => "Not enough on the shop floor: {$names}."]);
        }

        // A distinct error, so the till knows to ask a manager and send the sale again.
        if ($approvalToken === null) {
            throw ValidationException::withMessages(['stockApproval' => "Not enough on the shop floor: {$names}. A manager must approve selling it."]);
        }

        return $this->approvals->consume($approvalToken, 'below_zero', $till, $cashier);
    }

    public function openShift(Till $till, User $cashier): Shift
    {
        $shift = Shift::query()->where('till_id', $till->id)->whereNull('closed_at')->first();

        if (! $shift || $shift->user_id !== $cashier->id) {
            throw ValidationException::withMessages(['shift' => 'Start your shift on this till before selling.']);
        }

        return $shift;
    }

    public function salesLocation(Till $till): Location
    {
        return Location::query()
            ->where('branch_id', $till->branch_id)
            ->where('type', LocationType::ShopFloor)
            ->where('is_active', true)
            ->orderBy('id')
            ->first()
            ?? throw ValidationException::withMessages(['location' => 'This branch has no shop-floor location to sell from.']);
    }

    /**
     * @param  list<array{variantId: int, quantity: int, unitPriceCents?: int|null, discountCents?: int|null, approvalToken?: string|null}>  $input
     * @return list<array<string, mixed>>
     */
    private function priceLines(Till $till, User $cashier, array $input, bool $wholesale = false, ?CarbonImmutable $at = null): array
    {
        $variants = ProductVariant::query()->with(['taxRate', 'product.category'])->findMany(array_column($input, 'variantId'))->keyBy('id');
        $current = $this->prices->currentForVariants($variants->modelKeys(), $till->branch_id, $at);
        $limitPercent = $this->policy->discountLimitPercent($cashier, $till);
        $priceChangeApproval = $this->policy->priceChangeNeedsApproval($till);
        $blockBigDiscounts = $this->policy->discountAboveLimit($till) === 'blocked';
        $sellByTot = $this->policy->sellByTot($till);
        $lines = [];
        $promotionLines = [];

        foreach ($input as $i => $row) {
            $variant = $variants[$row['variantId']] ?? null;
            $unit = $row['unit'] ?? SaleLine::UNIT_BOTTLE;
            $tier = $unit === SaleLine::UNIT_TOT ? PriceTier::Tot : PriceTier::Retail;
            $price = $current[$row['variantId']][$tier->value] ?? null;
            // Wholesale customers pay the wholesale price where the item has one.
            $wholesalePrice = $wholesale && $unit === SaleLine::UNIT_BOTTLE && isset($current[$row['variantId']][PriceTier::Wholesale->value]);
            if ($wholesalePrice) {
                $price = $current[$row['variantId']][PriceTier::Wholesale->value];
            }

            if (! $variant || ! $variant->is_active || ! $variant->product->is_active) {
                throw ValidationException::withMessages(["lines.{$i}.variantId" => 'This item is not for sale.']);
            }
            if ($unit === SaleLine::UNIT_TOT && ! $sellByTot) {
                throw ValidationException::withMessages(["lines.{$i}.unit" => 'Selling by the tot is switched off.']);
            }
            if ($unit === SaleLine::UNIT_TOT && ! $variant->tot_ml) {
                throw ValidationException::withMessages(["lines.{$i}.unit" => "{$variant->display_name} is not sold by the tot."]);
            }
            if (! $price) {
                $what = $unit === SaleLine::UNIT_TOT ? 'tot' : 'retail';
                throw ValidationException::withMessages(["lines.{$i}.variantId" => "{$variant->display_name} has no {$what} price yet."]);
            }

            $qty = (int) $row['quantity'];
            $unitPrice = (int) ($row['unitPriceCents'] ?? $price->price_cents);
            $discount = (int) ($row['discountCents'] ?? 0);
            $gross = $qty * $unitPrice;
            $overridden = $unitPrice !== $price->price_cents;
            $bigDiscount = $discount > intdiv($gross * $limitPercent, 100);

            if ($discount > 0 && ! $cashier->can(Permissions::SALES_DISCOUNT_WITHIN_LIMIT)) {
                throw new AuthorizationException('You cannot give discounts.');
            }
            if ($discount > $gross) {
                throw ValidationException::withMessages(["lines.{$i}.discountCents" => 'The discount is larger than the line.']);
            }

            if ($bigDiscount && $blockBigDiscounts) {
                throw ValidationException::withMessages(["lines.{$i}.discountCents" => "Discounts above {$limitPercent}% are not allowed."]);
            }

            // Price changes follow Settings → Approvals ("allowed" ones stay visible on the line: list vs unit price).
            $needsOverride = $overridden && $priceChangeApproval;
            $approvedBy = null;
            if ($needsOverride || $bigDiscount) {
                $approvedBy = $this->approvals->consume($row['approvalToken'] ?? null, $needsOverride ? 'override' : 'discount', $till, $cashier);
            }

            $lines[] = [
                'variant' => $variant, // for pouring; removed before the line is saved
                'variant_id' => $variant->id,
                'unit' => $unit,
                'tot_ml' => $unit === SaleLine::UNIT_TOT ? $variant->tot_ml : null,
                'quantity' => $qty,
                'list_price_cents' => $price->price_cents,
                'unit_price_cents' => $unitPrice,
                'discount_cents' => $discount,
                'tax_rate_bp' => $variant->taxRate->rate_bp,
                'approved_by' => $approvedBy,
            ];
            $promotionLines[] = [
                'variantId' => $variant->id,
                'categoryIds' => array_values(array_filter([$variant->product->category_id, $variant->product->category?->parent_id])),
                'brandId' => $variant->product->brand_id,
                'unit' => $unit,
                'quantity' => $qty,
                'grossCents' => $gross,
                // A changed price or the wholesale price is already a deal: no promotion on top.
                'eligible' => ! $overridden && ! $wholesalePrice,
            ];
        }

        // Promotions (approved by the owner) running now at this branch: the best one per line.
        $promotions = $lines ? PromotionEngine::apply($this->promotions->runningAt($till->branch_id, $at ?? CarbonImmutable::now()), $promotionLines) : [];
        foreach ($lines as $i => $line) {
            $gross = $line['quantity'] * $line['unit_price_cents'];
            $promotion = $promotions[$i]['discountCents'] ?? 0;
            if ($gross < $line['discount_cents'] + $promotion) {
                throw ValidationException::withMessages(["lines.{$i}.discountCents" => 'With the promotion, this discount is more than the line.']);
            }
            $total = $gross - $promotion - $line['discount_cents'];
            $lines[$i] += [
                'promotion_id' => $promotion > 0 ? $promotions[$i]['promotionId'] : null,
                'promotion_discount_cents' => $promotion,
                'line_total_cents' => $total,
                'vat_cents' => Money::vatIncluded($total, $line['tax_rate_bp']),
            ];
        }

        return $lines;
    }

    /**
     * Non-cash tenders are applied first (they cannot exceed the total); cash covers
     * the rest and any excess is change.
     *
     * M-PESA is confirmed by Safaricom (a confirmation id from the till), never by the cashier's
     * word — except while the business runs M-PESA in manual mode (no Daraja yet).
     *
     * Cash is rounded to the owner's step (Settings → Payments); the difference is kept on the sale.
     *
     * @param  list<array{method: string, amountCents: int, reference?: string|null, cardLast4?: string|null, confirmationId?: int|null}>  $input
     * @return array{tenders: list<array<string, mixed>>, rounding: int}
     */
    private function settle(Till $till, array $input, int $total): array
    {
        $branchId = $till->branch_id;
        $methods = array_values(array_unique(array_column($input, 'method')));
        $refused = array_diff($methods, $this->policy->paymentMethods($till));
        if ($refused !== []) {
            throw ValidationException::withMessages(['tenders' => 'This shop does not take '.implode(', ', array_map(fn ($m) => SaleTender::LABELS[$m], $refused)).' payments.']);
        }
        if (count($methods) > 1 && ! $this->policy->splitAllowed($till)) {
            throw ValidationException::withMessages(['tenders' => 'Split payments are switched off: take one payment method.']);
        }

        $nonCash = array_filter($input, fn ($t) => $t['method'] !== SaleTender::CASH);
        $cashGiven = array_sum(array_map(fn ($t) => $t['amountCents'], array_filter($input, fn ($t) => $t['method'] === SaleTender::CASH)));
        $nonCashTotal = array_sum(array_column($nonCash, 'amountCents'));

        if ($nonCashTotal > $total) {
            throw ValidationException::withMessages(['tenders' => 'M-PESA, card and on-account amounts cannot be more than the total.']);
        }
        $exactCashDue = $total - $nonCashTotal;
        $cashDue = Money::roundTo($exactCashDue, $this->policy->cashRoundingCents($till));
        if ($cashGiven < $cashDue) {
            throw ValidationException::withMessages(['tenders' => 'KES '.number_format(($cashDue - $cashGiven) / 100, 2).' still to pay.']);
        }

        $manualMpesa = config('payments.mpesa.driver') === 'manual';
        $tenders = [];
        foreach (array_values($nonCash) as $i => $t) {
            // On account: no money changes hands; the receivable is the customer's.
            if ($t['method'] === SaleTender::CREDIT) {
                $tenders[] = ['method' => SaleTender::CREDIT, 'amount_cents' => $t['amountCents'], 'reference' => null, 'card_last4' => null, 'status' => SaleTender::CONFIRMED, 'confirmation_id' => null];

                continue;
            }
            $reference = isset($t['reference']) ? mb_strtoupper(trim($t['reference'])) : null;
            $confirmation = null;

            if ($t['method'] === SaleTender::MPESA && ! empty($t['confirmationId'])) {
                $confirmation = $this->mpesa->assertUsable((int) $t['confirmationId'], (int) $t['amountCents'], $branchId, 'tenders');
                $reference = $confirmation->receipt;
            } elseif ($t['method'] === SaleTender::MPESA && ! $manualMpesa) {
                throw ValidationException::withMessages(['tenders' => 'M-PESA must be confirmed by Safaricom: send a payment request or pick the customer\'s payment.']);
            }
            if (! $reference) {
                throw ValidationException::withMessages(['tenders' => $t['method'] === SaleTender::CARD ? 'Enter the card approval code.' : 'Enter the M-PESA code.']);
            }

            $tenders[] = [
                'method' => $t['method'],
                'amount_cents' => $t['amountCents'],
                'reference' => $reference,
                'card_last4' => $t['cardLast4'] ?? null,
                'status' => $confirmation ? SaleTender::CONFIRMED : SaleTender::UNVERIFIED,
                'confirmation_id' => $confirmation?->id,
            ];
        }
        $confirmationIds = array_filter(array_column($tenders, 'confirmation_id'));
        if (count($confirmationIds) !== count(array_unique($confirmationIds))) {
            throw ValidationException::withMessages(['tenders' => 'The same M-PESA payment is used twice.']);
        }

        if ($cashDue > 0 || $cashGiven > 0) {
            $tenders[] = [
                'method' => SaleTender::CASH,
                'amount_cents' => $cashDue,
                'tendered_cents' => $cashGiven,
                'change_cents' => $cashGiven - $cashDue,
                'status' => SaleTender::CONFIRMED,
            ];
        }

        return ['tenders' => $tenders, 'rounding' => $cashDue - $exactCashDue];
    }
}
