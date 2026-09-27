<?php

namespace Modules\Compliance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Organisation\Models\Branch;

/** A licence or permit a branch must keep valid (liquor licence, single business permit…). */
class Licence extends Model
{
    /** Licence types are a starting list: which ones apply REQUIRES VALIDATION with the county. */
    public const TYPES = [
        'liquor_licence' => 'Liquor licence (county)',
        'business_permit' => 'Single business permit',
        'fire' => 'Fire safety certificate',
        'health' => 'Health / food hygiene certificate',
        'excise' => 'Excise licence (KRA)',
        'other' => 'Other',
    ];

    protected $fillable = ['branch_id', 'type', 'name', 'number', 'issuer', 'issued_on', 'expires_on', 'print_on_receipt', 'notes', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['issued_on' => 'immutable_date', 'expires_on' => 'immutable_date', 'print_on_receipt' => 'boolean'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function daysLeft(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->expires_on, false);
    }
}
