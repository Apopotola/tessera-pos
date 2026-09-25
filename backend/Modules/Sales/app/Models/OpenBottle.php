<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Organisation\Models\Branch;

/** A bottle taken off the shelf to sell by the tot. History is immutable (DB trigger). */
class OpenBottle extends Model
{
    public const DOCUMENT_TYPE = 'open_bottle';

    public const NUMBER_PREFIX = 'OB';

    public const OPEN = 'open';

    public const FINISHED = 'finished';

    public const WRITTEN_OFF = 'written_off';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'volume_ml' => 'integer',
            'poured_ml' => 'integer',
            'unit_cost_cents' => 'integer',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function remainingMl(): int
    {
        return $this->volume_ml - $this->poured_ml;
    }

    /** Cost of pouring `ml` from this bottle, rounded half up. */
    public function costOf(int $ml): int
    {
        return intdiv($this->unit_cost_cents * $ml * 2 + $this->volume_ml, 2 * $this->volume_ml);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return HasMany<OpenBottlePour, $this> */
    public function pours(): HasMany
    {
        return $this->hasMany(OpenBottlePour::class);
    }
}
