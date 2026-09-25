<?php

namespace Modules\Reports\Reports\Sales;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Reports\Report;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportResult;

/** The main cashier-fraud signals: voids, discounts and price overrides, with who approved them. */
class ExceptionsReport extends Report
{
    public const FILTERS = ['dateRange', 'branch', 'user', 'status'];

    public function key(): string
    {
        return 'voids-discounts-overrides';
    }

    public function title(): string
    {
        return 'Voids, discounts & price overrides';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function description(): string
    {
        return 'Every item removed from a sale, discount and price change, with the cashier and approving manager.';
    }

    public function permission(): string
    {
        return Permissions::REPORTS_VIEW;
    }

    public function statuses(): array
    {
        return ['' => 'All', 'void' => 'Voids', 'discount' => 'Discounts', 'override' => 'Price overrides'];
    }

    public function columns(ReportFilters $filters): array
    {
        return [
            Column::datetime('at', 'When'),
            Column::text('type', 'Type'),
            Column::text('document', 'Sale'),
            Column::text('item', 'Item'),
            Column::number('quantity', 'Qty', total: false),
            Column::money('value', 'Value'),
            Column::text('cashier', 'Cashier'),
            Column::text('approver', 'Approved by'),
            Column::text('branch', 'Branch'),
            Column::text('reason', 'Reason'),
        ];
    }

    public function run(ReportFilters $filters, User $user): ReportResult
    {
        $rows = collect();
        $branches = $this->branchNames();
        $users = $this->userNames();

        if (in_array($filters->status, [null, 'discount', 'override'], true)) {
            $lines = DB::table('sale_lines as sl')
                ->join('sales as s', 's.id', '=', 'sl.sale_id')
                ->whereIn('s.branch_id', $filters->branchIds)
                ->where('s.completed_at', '>=', $filters->from)->where('s.completed_at', '<', $filters->to)
                ->when($filters->userId, fn ($q, $id) => $q->where('s.user_id', $id))
                ->where(fn ($q) => $q
                    ->when($filters->status !== 'override', fn ($q) => $q->orWhere('sl.discount_cents', '>', 0))
                    ->when($filters->status !== 'discount', fn ($q) => $q->orWhereColumn('sl.unit_price_cents', '!=', 'sl.list_price_cents')))
                ->select('s.completed_at', 's.number', 's.branch_id', 's.user_id', 'sl.variant_id', 'sl.quantity', 'sl.list_price_cents', 'sl.unit_price_cents', 'sl.discount_cents', 'sl.approved_by')
                ->get();
            $names = $this->variantNames($lines->pluck('variant_id'));

            foreach ($lines as $l) {
                $override = (int) $l->unit_price_cents !== (int) $l->list_price_cents;
                if ($override && $filters->status !== 'discount') {
                    $rows->push($this->row($l->completed_at, 'Price override', $l->number, $names[$l->variant_id] ?? '—', $l->quantity,
                        ((int) $l->list_price_cents - (int) $l->unit_price_cents) * (int) $l->quantity, $users[$l->user_id] ?? '—', $users[$l->approved_by] ?? '—', $branches[$l->branch_id] ?? '—', null));
                }
                if ((int) $l->discount_cents > 0 && $filters->status !== 'override') {
                    $rows->push($this->row($l->completed_at, 'Discount', $l->number, $names[$l->variant_id] ?? '—', $l->quantity,
                        (int) $l->discount_cents, $users[$l->user_id] ?? '—', $override ? '—' : ($users[$l->approved_by] ?? 'Within limit'), $branches[$l->branch_id] ?? '—', null));
                }
            }
        }

        if (in_array($filters->status, [null, 'void'], true)) {
            $voids = DB::table('audit_logs')
                ->where('action', 'sales.line.voided')
                ->whereIn('branch_id', $filters->branchIds)
                ->where('occurred_at', '>=', $filters->from)->where('occurred_at', '<', $filters->to)
                ->when($filters->userId, fn ($q, $id) => $q->where('user_id', $id))
                ->get();
            $names = $this->variantNames($voids->pluck('entity_id')->map(fn ($id) => (int) $id));

            foreach ($voids as $v) {
                $after = json_decode((string) $v->after, true) ?: [];
                $rows->push($this->row($v->occurred_at, 'Void', $v->reference, $names[(int) $v->entity_id] ?? '—', $after['quantity'] ?? null,
                    (int) ($after['value_cents'] ?? 0), $users[$v->user_id] ?? '—', $v->approver_id ? ($users[$v->approver_id] ?? '—') : 'Below threshold', $branches[$v->branch_id] ?? '—', $v->reason));
            }
        }

        return new ReportResult($rows->sortByDesc('at')->values()->all(), ['Voids are items removed from the cart before payment; the "Sale" column shows the till.']);
    }

    /** @return array<string, mixed> */
    private function row(mixed $at, string $type, ?string $document, string $item, mixed $qty, int $value, string $cashier, string $approver, string $branch, ?string $reason): array
    {
        return [
            'at' => (string) $at,
            'type' => $type,
            'document' => $document,
            'item' => $item,
            'quantity' => $qty === null ? null : (int) $qty,
            'value' => $value,
            'cashier' => $cashier,
            'approver' => $approver,
            'branch' => $branch,
            'reason' => $reason,
        ];
    }
}
