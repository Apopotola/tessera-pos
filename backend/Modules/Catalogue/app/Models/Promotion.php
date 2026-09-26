<?php

namespace Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;
use Modules\Sales\Models\SaleLine;

/** A time-boxed price rule, approved by the owner. See Support\PromotionEngine for how it applies. */
class Promotion extends Model
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const REJECTED = 'rejected';

    public const ENDED = 'ended';

    protected $fillable = ['name', 'status', 'discount_type', 'discount_value', 'min_quantity', 'unit', 'starts_on', 'ends_on', 'weekdays', 'time_from', 'time_to', 'branch_ids', 'requested_by'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'weekdays' => 'array',
            'branch_ids' => 'array',
            'discount_value' => 'integer',
            'min_quantity' => 'integer',
            'reviewed_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<PromotionTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(PromotionTarget::class);
    }

    /** @return HasMany<SaleLine, $this> */
    public function saleLines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
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

    /**
     * The rule as the engine (and the till, which runs the same rules) reads it.
     *
     * @return array<string, mixed>
     */
    public function rule(): array
    {
        $ids = fn (string $type) => $this->targets->where('target_type', $type)->pluck('target_id')->map(fn ($id) => (int) $id)->values()->all();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'discountType' => $this->discount_type,
            'discountValue' => $this->discount_value,
            'minQuantity' => $this->min_quantity,
            'unit' => $this->unit,
            'startsOn' => $this->starts_on->toDateString(),
            'endsOn' => $this->ends_on->toDateString(),
            'weekdays' => $this->weekdays,
            'timeFrom' => $this->time_from,
            'timeTo' => $this->time_to,
            'branchIds' => $this->branch_ids,
            'categoryIds' => $ids('category'),
            'brandIds' => $ids('brand'),
            'variantIds' => $ids('variant'),
        ];
    }
}
