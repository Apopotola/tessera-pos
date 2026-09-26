<?php

namespace Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Auth\Models\User;

/**
 * A staff account as seen by an administrator (frontend/types/users.ts → ManagedUser).
 *
 * @mixin User
 */
class ManagedUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->roles->first()?->name,
            'branchIds' => $this->branches->pluck('id')->values(),
            'hasPin' => $this->pin_hash !== null,
            'mfaEnabled' => $this->mfa_enabled_at !== null,
            'isActive' => $this->is_active,
            'lastLoginAt' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
