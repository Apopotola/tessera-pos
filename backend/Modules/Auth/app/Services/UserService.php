<?php

namespace Modules\Auth\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;

/** Staff accounts: role, branches, activation and till PINs. */
class UserService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): User
    {
        $this->assertCanGrant($data['role'], $actor);

        return DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => trim($data['name']),
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                // Till-only staff get an unusable random password; they sign in with a PIN.
                'password' => $data['password'] ?? Str::random(40),
                'must_change_password' => isset($data['password']),
            ]);
            $user->syncRoles([$data['role']]);
            $user->branches()->sync($data['branchIds'] ?? []);

            $this->audit->log('users.created', $user, after: $this->snapshot($user));

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, array $data, User $actor): User
    {
        $this->assertCanGrant($data['role'], $actor);

        if ($user->is($actor) && ! ($data['isActive'] ?? true)) {
            throw ValidationException::withMessages(['isActive' => 'You cannot deactivate your own account.']);
        }

        if ($user->hasRole(Roles::OWNER) && $data['role'] !== Roles::OWNER && User::role(Roles::OWNER)->count() === 1) {
            throw ValidationException::withMessages(['role' => 'The business must keep at least one Owner.']);
        }

        return DB::transaction(function () use ($user, $data) {
            $before = $this->snapshot($user);

            $user->fill([
                'name' => trim($data['name']),
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'is_active' => $data['isActive'] ?? $user->is_active,
            ])->save();
            $user->syncRoles([$data['role']]);
            $user->branches()->sync($data['branchIds'] ?? []);

            $after = $this->snapshot($user->fresh());
            $this->audit->log('users.updated', $user, $before, $after);

            return $user;
        });
    }

    public function setPin(User $user, string $pin): User
    {
        return DB::transaction(function () use ($user, $pin) {
            $user->forceFill(['pin_hash' => Hash::make($pin), 'pin_set_at' => now()])->save();
            // The PIN itself never reaches the audit log.
            $this->audit->log('users.pin.set', $user, after: ['pin_set' => true]);

            return $user;
        });
    }

    /** Only an Owner may create or promote another Owner. */
    private function assertCanGrant(string $role, User $actor): void
    {
        if ($role === Roles::OWNER && ! $actor->hasRole(Roles::OWNER)) {
            throw new AuthorizationException('Only an Owner can assign the Owner role.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(User $user): array
    {
        return [
            ...Arr::only($user->toArray(), ['name', 'email', 'phone', 'is_active']),
            'roles' => $user->getRoleNames()->values()->all(),
            'branch_ids' => $user->branches()->pluck('branches.id')->all(),
        ];
    }
}
