<?php

namespace Modules\Organisation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Organisation\Enums\LocationType;

class Location extends Model
{
    protected $fillable = ['branch_id', 'code', 'name', 'type', 'is_sellable', 'is_active'];

    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'is_sellable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
