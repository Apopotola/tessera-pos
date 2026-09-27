<?php

namespace Modules\Expenses\Models;

use Illuminate\Database\Eloquent\Model;

/** What an expense was for (transport, casual labour, electricity…). */
class ExpenseCategory extends Model
{
    protected $fillable = ['name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
