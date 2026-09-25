<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;
use Modules\Inventory\Enums\AdjustmentType;
use Modules\Inventory\Enums\DocumentStatus;
use Modules\Organisation\Models\Branch;
use Modules\Organisation\Models\Location;

class StockAdjustment extends Model
{
    public const DOCUMENT_TYPE = 'stock_adjustment';

    public const NUMBER_PREFIX = 'ADJ';

    protected $fillable = ['number', 'branch_id', 'location_id', 'type', 'stage', 'status', 'reason', 'requested_by', 'source_type', 'source_id'];

    protected function casts(): array
    {
        return [
            'type' => AdjustmentType::class,
            'status' => DocumentStatus::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<StockAdjustmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class);
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
