<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;

class SupplierInvoice extends Model
{
    public const MATCHED = 'matched';

    public const VARIANCE = 'variance';

    protected $fillable = [
        'supplier_id', 'invoice_number', 'invoice_date', 'due_date', 'subtotal_cents', 'vat_cents', 'total_cents',
        'expected_total_cents', 'variance_cents', 'match_status', 'note', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'subtotal_cents' => 'integer',
            'vat_cents' => 'integer',
            'total_cents' => 'integer',
            'expected_total_cents' => 'integer',
            'variance_cents' => 'integer',
        ];
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

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
