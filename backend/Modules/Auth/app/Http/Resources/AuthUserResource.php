<?php

namespace Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Auth\Models\User;
use Modules\Auth\Services\PasswordService;

/**
 * The signed-in user as the frontend sees it (frontend/types/auth.ts → AuthUser).
 *
 * @mixin User
 */
class AuthUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            // Set by an admin, or older than Settings → Staff → Password expiry.
            'mustChangePassword' => app(PasswordService::class)->mustChange($this->resource),
            'mfaEnabled' => $this->mfa_enabled_at !== null,
            'roles' => $this->getRoleNames()->values(),
            'permissions' => $this->getAllPermissions()->pluck('name')->sort()->values(),
        ];
    }
}
