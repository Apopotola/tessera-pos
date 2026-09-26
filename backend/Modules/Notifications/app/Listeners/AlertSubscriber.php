<?php

namespace Modules\Notifications\Listeners;

use Illuminate\Events\Dispatcher;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Compliance\Events\EtimsDocumentRejected;
use Modules\Compliance\Models\EtimsSubmission;
use Modules\Inventory\Services\StockQueryService;
use Modules\Notifications\Models\Alert;
use Modules\Notifications\Services\AlertService;
use Modules\Sales\Events\SaleCompleted;
use Modules\Sales\Events\SaleReturned;
use Modules\Sales\Events\ShiftClosed;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleLine;
use Modules\Sales\Models\SaleReturn;
use Modules\Sales\Models\Shift;
use Modules\Settings\Services\SettingsService;

/** Turns what happens in Sales and Compliance into alerts (Settings → Notifications). */
class AlertSubscriber
{
    public function __construct(
        private readonly AlertService $alerts,
        private readonly StockQueryService $stock,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Requirements, scenario 5: "A sale takes on-hand stock from 25 to 23. The system raises a
     * low-stock alert." Only when this sale crossed the level, so each item alerts once.
     */
    public function onSale(SaleCompleted $event): void
    {
        if (! $this->alerts->enabled(Alert::LOW_STOCK)) {
            return;
        }
        $sale = Sale::query()->with(['lines', 'branch'])->find($event->saleId);
        $sold = $sale?->lines->where('unit', SaleLine::UNIT_BOTTLE)->groupBy('variant_id')->map(fn ($lines) => $lines->sum('quantity'));
        if (! $sale || $sold->isEmpty()) {
            return;
        }

        $levels = $this->stock->levelsAt($sale->branch_id, $sold->keys()->all());
        $names = ProductVariant::query()->with('product')->findMany($sold->keys())->keyBy('id');
        foreach ($sold as $variantId => $quantity) {
            ['available' => $after, 'level' => $level] = $levels[$variantId];
            if ($after <= $level && $after + $quantity > $level) {
                $name = $names[$variantId]->display_name;
                $this->alerts->raise(Alert::LOW_STOCK, $sale->branch_id, "{$name} is low at {$sale->branch->name}",
                    "{$after} left, reorder level {$level}. Request a transfer or order more.",
                    ['variantId' => $variantId, 'available' => $after, 'level' => $level], "low_stock:{$sale->branch_id}:{$variantId}");
            }
        }
    }

    public function onReturn(SaleReturned $event): void
    {
        $return = SaleReturn::query()->with(['sale', 'branch', 'cashier'])->find($event->returnId);
        $threshold = (int) $this->settings->get('notifications.large_refund_cents', $return?->branch_id);
        if (! $return || $return->total_cents < $threshold) {
            return;
        }

        $this->alerts->raise(Alert::LARGE_REFUND, $return->branch_id, 'Large refund at '.$return->branch->name,
            'KES '.number_format($return->total_cents / 100, 2)." refunded on {$return->sale->number} by {$return->cashier->name}. Reason: {$return->reason}",
            ['returnId' => $return->id, 'saleNumber' => $return->sale->number, 'amountCents' => $return->total_cents]);
    }

    public function onShiftClosed(ShiftClosed $event): void
    {
        $shift = Shift::query()->with(['user', 'till', 'branch'])->find($event->shiftId);
        $allowed = (int) $this->settings->get('shifts.allowed_variance', $shift?->branch_id, $shift?->till_id);
        if (! $shift || abs((int) $shift->variance_cents) <= $allowed) {
            return;
        }

        $what = $shift->variance_cents < 0 ? 'short' : 'over';
        $this->alerts->raise(Alert::CASH_VARIANCE, $shift->branch_id, "Cash {$what} at {$shift->branch->name}",
            "{$shift->user->name}'s cash-up on {$shift->till->name} is {$what} by KES ".number_format(abs($shift->variance_cents) / 100, 2).'. Review and sign it off.',
            ['shiftId' => $shift->id, 'varianceCents' => (int) $shift->variance_cents]);
    }

    public function onEtimsRejected(EtimsDocumentRejected $event): void
    {
        $submission = EtimsSubmission::query()->find($event->submissionId);
        if (! $submission) {
            return;
        }

        $this->alerts->raise(Alert::ETIMS_FAILURE, $submission->branch_id, "KRA refused {$submission->document_number}",
            'eTIMS refused this '.($submission->document_type === EtimsSubmission::SALE ? 'invoice' : 'credit note').": {$submission->last_error}. Fix the item data and retry from the eTIMS monitor.",
            ['submissionId' => $submission->id], "etims_rejected:{$submission->id}", 24 * 365);
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            SaleCompleted::class => 'onSale',
            SaleReturned::class => 'onReturn',
            ShiftClosed::class => 'onShiftClosed',
            EtimsDocumentRejected::class => 'onEtimsRejected',
        ];
    }
}
