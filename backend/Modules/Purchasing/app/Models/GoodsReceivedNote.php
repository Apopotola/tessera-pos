<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;
use Modules\Organisation\Models\Location;
use Modules\Purchasing\Support\Vat;

class GoodsReceivedNote extends Model
{
    public const DOCUMENT_TYPE = 'goods_received_note';

    public const NUMBER_PREFIX = 'GRN';

    protected $fillable = ['number', 'purchase_order_id', 'supplier_id', 'branch_id', 'location_id', 'delivery_note_ref', 'note', 'received_by'];

    /** Value of good units received, incl. VAT — what the supplier invoice should match. */
    public function valueCents(): int
    {
        return $this->lines->sum(fn (GoodsReceivedLine $l) => Vat::line($l->quantity_received, $l->unit_cost_cents, $l->tax_rate_bp)['gross']);
    }

    /** @return HasMany<GoodsReceivedLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceivedLine::class);
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<SupplierInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }
}
