<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;
use Modules\Inventory\Enums\DocumentStatus;
use Modules\Organisation\Models\Location;

class SupplierReturn extends Model
{
    public const DOCUMENT_TYPE = 'supplier_return';

    public const NUMBER_PREFIX = 'RTS';

    protected $fillable = ['number', 'supplier_id', 'branch_id', 'location_id', 'status', 'reason', 'credit_note_ref', 'requested_by'];

    protected function casts(): array
    {
        return ['status' => DocumentStatus::class, 'reviewed_at' => 'immutable_datetime'];
    }

    /** @return HasMany<SupplierReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierReturnLine::class);
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
