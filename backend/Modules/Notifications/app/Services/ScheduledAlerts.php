<?php

namespace Modules\Notifications\Services;

use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Compliance\Models\EtimsSubmission;
use Modules\Dashboard\Services\KpiService;
use Modules\Inventory\Services\StockQueryService;
use Modules\Notifications\Models\Alert;
use Modules\Organisation\Models\Branch;
use Modules\Sales\Models\SaleTender;
use Modules\Sales\Models\Shift;
use Modules\Settings\Services\SettingsService;

/** Alerts raised on a schedule: end-of-day summary, nightly low-stock check, eTIMS waiting too long. */
class ScheduledAlerts
{
    public function __construct(
        private readonly AlertService $alerts,
        private readonly SettingsService $settings,
        private readonly KpiService $kpis,
        private readonly StockQueryService $stock,
    ) {}

    /**
     * The owner's end-of-day summary at Settings → Notifications → daily report time
     * (default 9:00 pm). Runs every minute; sends once per day, from that time on.
     */
    public function dailySummary(bool $force = false): ?Alert
    {
        $time = (string) ($this->settings->get('notifications.daily_report_time') ?? '21:00');
        if (! $force && now()->format('H:i') < $time) {
            return null;
        }

        $outlets = Branch::query()->where('is_active', true)->where('is_warehouse', false)->pluck('id')->all();
        $today = $this->kpis->period($outlets, now()->startOfDay(), now()->addSecond());
        $tenders = SaleTender::query()->whereIn('shift_id', Shift::query()->select('id')->whereIn('branch_id', $outlets))
            ->where('created_at', '>=', now()->startOfDay())->where('amount_cents', '>', 0)
            ->selectRaw('method, SUM(amount_cents) AS total')->groupBy('method')->pluck('total', 'method');
        $exceptions = $this->kpis->exceptionsToday($outlets)['totals'];
        $margin = KpiService::margin($today);
        $toReview = Shift::query()->whereIn('branch_id', $outlets)->whereNotNull('closed_at')->whereNull('reviewed_at')->count();
        $etims = EtimsSubmission::query()->whereIn('status', [EtimsSubmission::PENDING, EtimsSubmission::FAILED, EtimsSubmission::REJECTED])->count();
        $kes = fn (int $cents) => 'KES '.number_format($cents / 100, 2);

        $lines = [
            "Net sales {$kes($today['netCents'])} from {$today['transactions']} sales".($margin !== null ? ", margin {$margin}%." : '.'),
            'Cash '.$kes((int) ($tenders['cash'] ?? 0)).', M-PESA '.$kes((int) ($tenders['mpesa'] ?? 0)).', card '.$kes((int) ($tenders['card'] ?? 0))
                .(isset($tenders['credit']) ? ', on account '.$kes((int) $tenders['credit']) : '').'.',
            "Discounts {$kes($exceptions['discounts']['cents'])}, removed items {$kes($exceptions['voids']['cents'])}, refunds {$kes($exceptions['refunds']['cents'])}.",
            "Cash-ups waiting for sign-off: {$toReview}. eTIMS invoices not yet signed: {$etims}.",
        ];

        return $this->alerts->raise(Alert::DAILY_SUMMARY, null, 'End of day, '.now()->format('D j M'), implode("\n", $lines),
            ['netCents' => $today['netCents'], 'transactions' => $today['transactions']], 'daily_summary:'.now()->toDateString(), 23);
    }

    /** Requirements: "a nightly job plus real-time checks raise low-stock alerts" — the nightly digest. */
    public function lowStockDigest(): ?Alert
    {
        $owner = User::role(Roles::OWNER)->where('is_active', true)->first();
        if (! $owner) {
            return null;
        }
        $count = $this->stock->dashboard($owner)['lowStock'];
        if ($count === 0) {
            return null;
        }
        $top = collect($this->stock->lowStockList($owner, 5))->map(fn ($r) => "{$r['displayName']} ({$r['branchCode']}: {$r['available']} left)")->implode('; ');

        return $this->alerts->raise(Alert::LOW_STOCK, null, "{$count} item(s) at or below their reorder level",
            "Fast movers first: {$top}.", ['count' => $count], 'low_stock_digest:'.now()->toDateString(), 20);
    }

    /** Invoices KRA has not signed within the alert window (default 60 minutes); at most every 6 hours. */
    public function etimsOverdue(): ?Alert
    {
        $minutes = (int) config('compliance.etims.pending_alert_minutes', 60);
        $waiting = EtimsSubmission::query()->whereIn('status', [EtimsSubmission::PENDING, EtimsSubmission::FAILED])
            ->where('created_at', '<', now()->subMinutes($minutes));
        $count = (clone $waiting)->count();
        if ($count === 0) {
            return null;
        }
        $oldest = (clone $waiting)->min('created_at');

        return $this->alerts->raise(Alert::ETIMS_FAILURE, null, "{$count} eTIMS invoice(s) not signed after {$minutes} minutes",
            'The oldest has waited '.now()->diffForHumans($oldest, true).'. Check the connection to KRA on the eTIMS monitor.',
            ['count' => $count], 'etims_overdue', 6);
    }
}
