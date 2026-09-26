<?php

namespace Modules\Auth\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Auth\Database\Factories\UserFactory;
use Modules\Organisation\Models\Branch;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /** Spatie permission guard: the SPA authenticates through the session (web) guard. */
    protected string $guard_name = 'web';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'is_active',
        'must_change_password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'pin_hash',
        'remember_token',
        'mfa_secret',
        'mfa_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'pin_set_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'mfa_secret' => 'encrypted',
            'mfa_enabled_at' => 'datetime',
            'mfa_recovery_codes' => 'array',
            'mfa_last_step' => 'integer',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** Stored in canonical +254 form so phone sign-in matches however it is typed. */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null || trim($value) === '' ? null : (PhoneNumber::normalize($value) ?? $value));
    }

    /** Email is case-insensitive for sign-in. */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value) => mb_strtolower(trim($value)));
    }

    /** @return BelongsToMany<Branch, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }
}
