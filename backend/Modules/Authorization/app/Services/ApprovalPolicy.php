<?php

namespace Modules\Authorization\Services;

use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Settings\Services\SettingsService;

/**
 * Maker–checker: who may approve work they raised themselves. Nobody, unless Tessera support has
 * switched on Settings → Staff → "Owners and Tessera support may approve their own work"; then
 * owners and Tessera support may (a business run by its owner). Everyone else always needs a second person.
 */
class ApprovalPolicy
{
    public function __construct(private readonly SettingsService $settings) {}

    public function mayApproveOwn(User $user): bool
    {
        return $user->hasAnyRole([Roles::OWNER, Roles::TESSERA_ADMIN])
            && (bool) $this->settings->get('approvals.owner_self_approval');
    }
}
