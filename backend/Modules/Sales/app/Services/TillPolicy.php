<?php

namespace Modules\Sales\Services;

use Modules\Auth\Models\User;
use Modules\Organisation\Models\Till;
use Modules\Sales\Models\SaleTender;
use Modules\Settings\Services\SettingsService;

/**
 * The owner's till rules (Settings), resolved for one till: most specific wins
 * (till → branch → business → default). The till screen gets them up front through
 * forTill(); the sale, return and void endpoints re-check them on every request.
 */
class TillPolicy
{
    public function __construct(private readonly SettingsService $settings) {}

    public function value(string $key, Till $till): mixed
    {
        return $this->settings->get($key, $till->branch_id, $till->id);
    }

    /** Largest discount (% of the line) this person may give without a manager: the best of their roles. */
    public function discountLimitPercent(User $user, Till $till): int
    {
        $limits = (array) $this->value('staff.max_discount', $till);

        return (int) $user->roles->map(fn ($role) => $limits[$role->name] ?? 0)->max();
    }

    /** Invoice number prefix, e.g. "INV-" ("" = the original BRANCH-S-000001 format). */
    public function invoicePrefix(Till $till): string
    {
        return (string) $this->value('receipts.invoice_prefix', $till);
    }

    /** "approval" | "blocked" */
    public function discountAboveLimit(Till $till): string
    {
        return (string) $this->value('approvals.discount_above_limit', $till);
    }

    public function priceChangeNeedsApproval(Till $till): bool
    {
        return $this->value('approvals.price_change', $till) === 'approval';
    }

    public function refundNeedsApproval(Till $till): bool
    {
        return $this->value('approvals.refund', $till) === 'approval';
    }

    /** Removing a line from an open sale: null = never needs a manager; otherwise lines worth more than this do. */
    public function voidApprovalThresholdCents(Till $till): ?int
    {
        return match ($this->value('approvals.remove_item', $till)) {
            'approval' => 0,
            'over_amount' => (int) $this->value('approvals.remove_item_threshold', $till),
            default => null,
        };
    }

    /** "allow" | "approval" | "block" */
    public function belowZero(Till $till): string
    {
        return (string) $this->value('stock.below_zero', $till);
    }

    /** @return list<string> tender methods the till may take, in the owner's button order */
    public function paymentMethods(Till $till): array
    {
        $accepted = (array) $this->value('payments.accepted_methods', $till);
        $order = (array) $this->value('sales.payment_methods_order', $till);
        $methods = [SaleTender::MPESA, SaleTender::CASH, SaleTender::CARD];
        $ordered = array_values(array_unique([...array_intersect($order, $methods), ...$methods]));

        return array_values(array_filter($ordered, fn ($m) => in_array($m, $accepted, true)));
    }

    public function splitAllowed(Till $till): bool
    {
        return in_array('split', (array) $this->value('payments.accepted_methods', $till), true);
    }

    /** Cash is rounded to this many cents (0 = no rounding). */
    public function cashRoundingCents(Till $till): int
    {
        return (int) $this->value('payments.cash_rounding', $till) * 100;
    }

    public function stkPush(Till $till): bool
    {
        return (bool) $this->value('payments.stk_push', $till);
    }

    public function etimsEnabled(Till $till): bool
    {
        return (bool) $this->value('integrations.etims_enabled', $till);
    }

    /** Categories this branch sells (empty = all). @return list<int> */
    public function categoryIds(Till $till): array
    {
        return array_map('intval', (array) $this->value('branch.products_sold', $till));
    }

    public function sellByTot(Till $till): bool
    {
        return (bool) $this->value('features.sell_by_tot', $till);
    }

    public function blindCashUp(Till $till): bool
    {
        return (bool) $this->value('shifts.blind_cashup', $till);
    }

    /**
     * Everything the till screen needs before anyone signs in (no secrets).
     *
     * @return array<string, mixed>
     */
    public function forTill(Till $till): array
    {
        $limits = (array) $this->value('staff.max_discount', $till);

        return [
            'discountLimits' => array_map('intval', $limits),
            'discountAboveLimit' => $this->discountAboveLimit($till),
            'priceChangeNeedsApproval' => $this->priceChangeNeedsApproval($till),
            'refundNeedsApproval' => $this->refundNeedsApproval($till),
            'voidApprovalThresholdCents' => $this->voidApprovalThresholdCents($till),
            'belowZero' => $this->belowZero($till),
            'paymentMethods' => $this->paymentMethods($till),
            'splitAllowed' => $this->splitAllowed($till),
            'cashRoundingCents' => $this->cashRoundingCents($till),
            'stkPush' => $this->stkPush($till),
            'etimsEnabled' => $this->etimsEnabled($till),
            'sellByTot' => $this->sellByTot($till),
            'ageCheck' => (bool) $this->value('sales.age_check_prompt', $till),
            'blindCashUp' => $this->blindCashUp($till),
            'layout' => $this->value('sales.layout', $till),
            'touchMode' => $this->value('sales.touch_mode', $till),
            'quickButtons' => array_values((array) $this->value('sales.quick_buttons', $till)),
            'favouritesMode' => $this->value('sales.favourites_mode', $till),
            'receipt' => $this->receipt($till->branch_id, $till->id),
        ];
    }

    /**
     * How receipts look at a branch or till; sent with every sale so reprints match.
     *
     * @return array<string, mixed>
     */
    public function receipt(int $branchId, ?int $tillId): array
    {
        $get = fn (string $key) => $this->settings->get($key, $branchId, $tillId);

        return [
            'headerLines' => $this->lines($get('receipts.header_lines')),
            'footerLines' => $this->lines($get('receipts.footer_lines')),
            'show' => array_values((array) $get('receipts.show')),
            'paperSize' => $get('receipts.paper_size'),
            'printBehaviour' => $get('receipts.print_behaviour'),
            'returnWindowDays' => (int) config('sales.return_window_days', 7),
            'logo' => $this->settings->present($this->settings->definition('branding.receipt_logo'), $get('branding.receipt_logo')),
        ];
    }

    /** @return list<string> */
    private function lines(mixed $value): array
    {
        return $value === null || $value === '' ? [] : explode("\n", (string) $value);
    }
}
