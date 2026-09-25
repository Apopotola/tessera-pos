<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only: ml taken from an open bottle, sold as tots or written off. */
class OpenBottlePour extends Model
{
    public const SALE = 'sale';

    public const WRITE_OFF = 'write_off';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ml' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
