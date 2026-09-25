<?php

namespace Modules\Organisation\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;

class Branch extends Model
{
    protected $fillable = ['business_id', 'code', 'name', 'phone', 'address', 'is_warehouse', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_warehouse' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<Location, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** @param Builder<Branch> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
