<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Platform\Models\PlatformUser;

/**
 * Tenant lifecycle Actions are called from Artisan (no actor: an operator with shell access
 * to the deploy host) and from the platform panel (a platform user). The check lives in the
 * Action so both callers are held to it (spec §6.2).
 */
trait AuthorizesPlatformActor
{
    private function authorizeTenantManagement(?PlatformUser $actor): void
    {
        if ($actor === null) {
            return;
        }

        if ($actor->status !== 'active' || ! $actor->role->canManageTenants()) {
            throw new AuthorizationException('This platform role may not manage tenants.');
        }
    }
}
