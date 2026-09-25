<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;
use Modules\Organisation\Models\Branch;
use Modules\Organisation\Models\Location;
use Modules\Purchasing\Enums\PurchaseOrderStatus;
use Modules\Purchasing\Support\Vat;

class PurchaseOrder extends Model
{
    public const NUMBER_PREFIX = 'PO';

    protected $fillable = ['number', 'supplier_id', 'branch_id', 'location_id', 'status', 'expected_date', 'note', 'created_by'];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'expected_date' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @return array{net: int, vat: int, gross: int} */
    public function totals(): array
    {
        return $this->lines->reduce(function (array $sum, PurchaseOrderLine $line) {
            $t = Vat::line($line->quantity_ordered, $line->unit_cost_cents, $line->tax_rate_bp);

            return ['net' => $sum['net'] + $t['net'], 'vat' => $sum['vat'] + $t['vat'], 'gross' => $sum['gross'] + $t['gross']];
        }, ['net' => 0, 'vat' => 0, 'gross' => 0]);
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /** @return HasMany<GoodsReceivedNote, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceivedNote::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
