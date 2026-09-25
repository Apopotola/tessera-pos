<?php

namespace Modules\Organisation\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Organisation\Models\Branch;

class BranchAccessService
{
    /**
     * Branches the user may work in, ordered by code.
     *
     * @return Collection<int, Branch>
     */
    public function branchesFor(User $user): Collection
    {
        $query = Branch::query()->active()->orderBy('code');

        if (! $user->can(Permissions::ORGANISATION_ALL_BRANCHES)) {
            $query->whereHas('users', fn ($q) => $q->whereKey($user->getKey()));
        }

        return $query->get();
    }

    public function canAccess(User $user, int $branchId): bool
    {
        return $this->branchesFor($user)->contains('id', $branchId);
    }
}
