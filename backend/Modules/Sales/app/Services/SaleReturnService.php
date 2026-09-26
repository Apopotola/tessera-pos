<?php

namespace Modules\Sales\Services;

use App\Services\DocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Compliance\Services\EtimsOutbox;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\StockEntry;
use Modules\Inventory\Services\StockLedger;
use Modules\Organisation\Enums\LocationType;
use Modules\Organisation\Models\Location;
use Modules\Organisation\Models\Till;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleLine;
use Modules\Sales\Models\SaleReturn;
use Modules\Sales\Models\SaleTender;
use Modules\Sales\Support\Money;

/**
 * Customer returns against a sale, approved at the till by a manager's PIN.
 * Sealed bottles go back to the shop floor; opened or broken ones to quarantine.
 * The refund is paid in cash from the current shift's drawer. eTIMS credit note pending.
 */
class SaleReturnService
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly StockLedger $ledger,
        private readonly TillApprovalService $approvals,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly EtimsOutbox $etims,
        private readonly TillPolicy $policy,
    ) {}

    /** @param array{saleId: int, reason: string, approvalToken?: string|null, lines: list<array{saleLineId: int, quantity: int, restock: bool}>} $data */
    public function create(Till $till, User $cashier, array $data): SaleReturn
    {
        $shift = $this->sales->openShift($till, $cashier);

        return DB::transaction(function () use ($till, $cashier, $data, $shift) {
            $sale = Sale::query()->with('lines')->lockForUpdate()->findOrFail($data['saleId']);

            if ($sale->branch_id !== $till->branch_id) {
                throw ValidationException::withMessages(['saleId' => 'This sale was made at another branch.']);
            }
            $window = (int) config('sales.return_window_days', 7);
            if ($sale->completed_at->lt(now()->subDays($window))) {
                throw ValidationException::withMessages(['saleId' => "Returns are accepted within {$window} days of the sale."]);
            }

            // Settings → Approvals: refunds need a manager's PIN, or are allowed and logged.
            $approverId = $this->policy->refundNeedsApproval($till)
                ? $this->approvals->consume($data['approvalToken'] ?? null, 'refund', $till, $cashier)
                : null;
            $etims = $sale->etims_status !== Sale::ETIMS_NOT_REQUIRED;
            $floor = $this->sales->salesLocation($till);
            $quarantine = Location::query()->where('branch_id', $till->branch_id)->where('type', LocationType::Quarantine)->first() ?? $floor;
            $number = $this->numbers->next($till->branch, SaleReturn::NUMBER_PREFIX);

            $rows = [];
            foreach ($data['lines'] as $i => $input) {
                $line = $sale->lines->firstWhere('id', $input['saleLineId'])
                    ?? throw ValidationException::withMessages(["lines.{$i}.saleLineId" => 'This item is not on the sale.']);
                if ($line->unit === SaleLine::UNIT_TOT) {
                    throw ValidationException::withMessages(["lines.{$i}.saleLineId" => 'Poured tots cannot be returned.']);
                }
                if ($input['quantity'] > $line->returnable()) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => "Only {$line->returnable()} of this item can still be returned."]);
                }

                $rows[] = [
                    'line' => $line,
                    'quantity' => (int) $input['quantity'],
                    'restock' => (bool) $input['restock'],
                    'amount' => Money::proportion($line->line_total_cents, (int) $input['quantity'], $line->quantity),
                    'vat' => Money::proportion($line->vat_cents, (int) $input['quantity'], $line->quantity),
                ];
            }

            $total = array_sum(array_column($rows, 'amount'));
            $return = SaleReturn::query()->create([
                'number' => $number,
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'till_id' => $till->id,
                'shift_id' => $shift->id,
                'user_id' => $cashier->id,
                'approved_by' => $approverId,
                'reason' => trim($data['reason']),
                'total_cents' => $total,
                'vat_cents' => array_sum(array_column($rows, 'vat')),
                'etims_status' => $etims ? 'pending' : Sale::ETIMS_NOT_REQUIRED,
            ]);

            $entries = [];
            foreach ($rows as $row) {
                $return->lines()->create([
                    'sale_line_id' => $row['line']->id,
                    'variant_id' => $row['line']->variant_id,
                    'quantity' => $row['quantity'],
                    'restocked' => $row['restock'],
                    'amount_cents' => $row['amount'],
                ]);
                $row['line']->increment('returned_quantity', $row['quantity']);
                $entries[] = new StockEntry(
                    $row['restock'] ? $floor : $quarantine,
                    $row['line']->variant_id,
                    $row['quantity'],
                    MovementType::CustomerReturn,
                    $row['line']->unit_cost_cents,
                    "Return of {$sale->number}".($row['restock'] ? '' : ' (not resaleable)'),
                );
            }

            $this->ledger->post($entries, SaleReturn::DOCUMENT_TYPE, $return->id, $number, $cashier->id, $approverId);

            // A sale put on account is refunded to the account first; the rest in cash.
            $onAccount = (int) SaleTender::query()->where('sale_id', $sale->id)->where('method', SaleTender::CREDIT)->sum('amount_cents');
            $alreadyBack = -(int) SaleTender::query()->where('method', SaleTender::CREDIT)
                ->whereIn('sale_return_id', SaleReturn::query()->where('sale_id', $sale->id)->select('id'))->sum('amount_cents');
            $toAccount = min($total, max(0, $onAccount - $alreadyBack));
            $refunds = array_filter([SaleTender::CREDIT => $toAccount, SaleTender::CASH => $total - $toAccount]);
            foreach ($refunds as $method => $amount) {
                SaleTender::query()->create([
                    'shift_id' => $shift->id,
                    'sale_return_id' => $return->id,
                    'method' => $method,
                    'amount_cents' => -$amount,
                    'status' => SaleTender::CONFIRMED,
                ]);
            }

            $fullyReturned = $sale->lines->every(fn ($l) => $l->refresh()->returnable() === 0);
            $sale->forceFill(['status' => $fullyReturned ? 'returned' : 'partially_returned'])->save();

            // Credit note referencing the original invoice, queued with the return.
            if ($etims) {
                $this->etims->queueCreditNote($return, $sale);
            }

            $this->audit->log('sales.return.completed', $return, after: ['number' => $number, 'sale' => $sale->number, 'total_cents' => $total],
                reason: $return->reason, userId: $cashier->id, approverId: $approverId, branchId: $sale->branch_id);

            return $return;
        });
    }
}
