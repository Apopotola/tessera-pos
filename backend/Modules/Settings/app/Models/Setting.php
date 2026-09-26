<?php

namespace Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

/** One stored setting value at one scope. Defaults live in SettingsRegistry, not here. */
class Setting extends Model
{
    public const CREATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'json', 'scope_id' => 'integer', 'updated_at' => 'immutable_datetime'];
    }
}
