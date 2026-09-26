<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;

/** Cash moved from the till drawer to the safe, witnessed by a manager. Append-only (DB trigger). */
class CashDrop extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function witness(): BelongsTo
    {
        return $this->belongsTo(User::class, 'witnessed_by');
    }
}
