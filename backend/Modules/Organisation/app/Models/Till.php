<?php

namespace Modules\Organisation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Till extends Model
{
    protected $fillable = ['branch_id', 'name', 'description', 'default_float_cents', 'is_active'];

    protected $hidden = ['device_token_hash'];

    protected function casts(): array
    {
        return [
            'default_float_cents' => 'integer',
            'paired_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
