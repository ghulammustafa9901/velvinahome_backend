<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Identity\Enums\StaffPermission;
use Modules\Identity\Models\StaffUser;

/**
 * Staff-facing Actions check permissions themselves, so the admin panel, a console command
 * and a future admin API are all held to the same rule (spec §6.2).
 */
trait AuthorizesStaff
{
    private function authorizeStaff(StaffUser $actor, StaffPermission $permission): void
    {
        if ($actor->status !== 'active' || ! $actor->can($permission->value)) {
            throw new AuthorizationException("This action requires the {$permission->value} permission.");
        }
    }
}
