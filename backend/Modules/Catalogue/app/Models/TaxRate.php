<?php

namespace Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    protected $fillable = ['code', 'name', 'rate_bp', 'is_active'];

    protected function casts(): array
    {
        return [
            'rate_bp' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
