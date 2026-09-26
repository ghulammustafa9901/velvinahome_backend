<?php

declare(strict_types=1);

namespace Modules\Support\Authorization;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Actions authorise their own caller (spec §6.2), so the admin, the API, a console command and
 * a future integration API are held to the same rule.
 *
 * A null actor is the system itself — provisioning, imports, scheduled jobs — which runs with
 * operator authority. Every human caller passes their account.
 */
trait ChecksPermission
{
    private function authorizePermission(?Authorizable $actor, string $permission): void
    {
        if ($actor !== null && ! $actor->can($permission)) {
            throw new AuthorizationException("This action requires the {$permission} permission.");
        }
    }
}
