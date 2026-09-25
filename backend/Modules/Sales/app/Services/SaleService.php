<?php

namespace Modules\Sales\Services;

use App\Services\DocumentNumberService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Enums\PriceTier;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Catalogue\Services\PriceResolver;
use Modules\Compliance\Services\EtimsOutbox;
use Modules\Customers\Models\Customer;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\StockEntry;
use Modules\Inventory\Services\StockLedger;
use Modules\Organisation\Enums\LocationType;
use Modules\Organisation\Models\Location;
use Modules\Organisation\Models\Till;
use Modules\Payments\Services\MpesaAllocator;
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
    ) {}

    /**
     * @param  array{clientId: string, customerPin?: string|null, lines: list<array{variantId: int, unit?: string|null, quantity: int, unitPriceCents?: int|null, discountCents?: int|null, approvalToken?: string|null}>, tenders: list<array{method: string, amountCents: int, reference?: string|null, cardLast4?: string|null}>}  $data
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
        $lines = $this->priceLines($till, $cashier, $data['lines'], (bool) $customer?->is_wholesale);
        $total = array_sum(array_column($lines, 'line_total_cents'));
        $tenders = $this->settle($data['tenders'], $total, $till->branch_id);

        return DB::transaction(function () use ($till, $cashier, $data, $shift, $location, $lines, $total, $tenders, $customer) {
            // Reserve the id first so the ledger can reference the sale and give us exact costs.
            $saleId = (int) DB::selectOne("SELECT nextval('sales_id_seq') AS id")->id;
            $number = $this->numbers->next($till->branch, Sale::NUMBER_PREFIX);

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
                'discount_cents' => array_sum(array_column($lines, 'discount_cents')),
                'total_cents' => $total,
                'vat_cents' => array_sum(array_column($lines, 'vat_cents')),
                'cost_cents' => array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_cost_cents'], $lines)),
                'status' => 'completed',
                'etims_status' => 'pending',
                'completed_at' => now(),
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
            $this->etims->queueSale($sale);

            $this->audit->log('sales.sale.completed', $sale, after: [
                'number' => $number,
                'total_cents' => $total,
                'tenders' => array_map(fn ($t) => [$t['method'], $t['amount_cents']], $tenders),
                'approved_lines' => count(array_filter($lines, fn ($l) => $l['approved_by'] !== null)),
            ], userId: $cashier->id, branchId: $till->branch_id, reference: "till:{$till->id}");

            return ['sale' => $sale, 'replayed' => false];
        });
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
    private function priceLines(Till $till, User $cashier, array $input, bool $wholesale = false): array
    {
        $variants = ProductVariant::query()->with(['taxRate', 'product'])->findMany(array_column($input, 'variantId'))->keyBy('id');
        $current = $this->prices->currentForVariants($variants->modelKeys(), $till->branch_id);
        $limitPercent = (int) config('sales.discount_limit_percent', 5);
        $lines = [];

        foreach ($input as $i => $row) {
            $variant = $variants[$row['variantId']] ?? null;
            $unit = $row['unit'] ?? SaleLine::UNIT_BOTTLE;
            $tier = $unit === SaleLine::UNIT_TOT ? PriceTier::Tot : PriceTier::Retail;
            $price = $current[$row['variantId']][$tier->value] ?? null;
            // Wholesale customers pay the wholesale price where the item has one.
            if ($wholesale && $unit === SaleLine::UNIT_BOTTLE && isset($current[$row['variantId']][PriceTier::Wholesale->value])) {
                $price = $current[$row['variantId']][PriceTier::Wholesale->value];
            }

            if (! $variant || ! $variant->is_active || ! $variant->product->is_active) {
                throw ValidationException::withMessages(["lines.{$i}.variantId" => 'This item is not for sale.']);
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

            $approvedBy = null;
            if ($overridden || $bigDiscount) {
                $approvedBy = $this->approvals->consume($row['approvalToken'] ?? null, $overridden ? 'override' : 'discount', $till, $cashier);
            }

            $lineTotal = $gross - $discount;
            $bp = $variant->taxRate->rate_bp;
            $lines[] = [
                'variant' => $variant, // for pouring; removed before the line is saved
                'variant_id' => $variant->id,
                'unit' => $unit,
                'tot_ml' => $unit === SaleLine::UNIT_TOT ? $variant->tot_ml : null,
                'quantity' => $qty,
                'list_price_cents' => $price->price_cents,
                'unit_price_cents' => $unitPrice,
                'discount_cents' => $discount,
                'line_total_cents' => $lineTotal,
                'vat_cents' => Money::vatIncluded($lineTotal, $bp),
                'tax_rate_bp' => $bp,
                'approved_by' => $approvedBy,
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
     * @param  list<array{method: string, amountCents: int, reference?: string|null, cardLast4?: string|null, confirmationId?: int|null}>  $input
     * @return list<array<string, mixed>>
     */
    private function settle(array $input, int $total, int $branchId): array
    {
        $nonCash = array_filter($input, fn ($t) => $t['method'] !== SaleTender::CASH);
        $cashGiven = array_sum(array_map(fn ($t) => $t['amountCents'], array_filter($input, fn ($t) => $t['method'] === SaleTender::CASH)));
        $nonCashTotal = array_sum(array_column($nonCash, 'amountCents'));

        if ($nonCashTotal > $total) {
            throw ValidationException::withMessages(['tenders' => 'M-PESA and card payments cannot be more than the total.']);
        }
        $cashDue = $total - $nonCashTotal;
        if ($cashGiven < $cashDue) {
            throw ValidationException::withMessages(['tenders' => 'KES '.number_format(($cashDue - $cashGiven) / 100, 2).' still to pay.']);
        }

        $manualMpesa = config('payments.mpesa.driver') === 'manual';
        $tenders = [];
        foreach (array_values($nonCash) as $i => $t) {
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

        return $tenders;
    }
}
