<?php

namespace Modules\Inventory\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Models\User;
use Modules\Organisation\Services\BranchAccessService;

/** Authorisation rules shared by every inventory workflow. */
class InventoryGuard
{
    public function __construct(private readonly BranchAccessService $branches) {}

    /** @param list<string> $permissions any one is enough */
    public function requireAny(User $user, array $permissions): void
    {
        if (! $user->canAny($permissions)) {
            throw new AuthorizationException;
        }
    }

    public function requireBranch(User $user, int ...$branchIds): void
    {
        foreach ($branchIds as $branchId) {
            if ($this->branches->canAccess($user, $branchId)) {
                return;
            }
        }

        throw new AuthorizationException('You do not work at this branch.');
    }

    /** Maker–checker: the person who raised a document cannot approve it. */
    public function requireDifferentPerson(?int $makerId, User $checker): void
    {
        if ($makerId !== null && $makerId === $checker->id) {
            throw new AuthorizationException('Someone else must approve this — you raised it.');
        }
    }

    public function requireStatus(\BackedEnum $current, \BackedEnum ...$allowed): void
    {
        if (! in_array($current, $allowed, true)) {
            throw ValidationException::withMessages(['status' => "Not allowed while the document is {$current->value}."]);
        }
    }
}
