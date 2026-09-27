<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament;

use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Admin visibility follows the same permission names the Actions enforce.
 */
final class StaffAccess
{
    public static function can(string $permission): bool
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable && $user->can($permission);
    }

    public static function actor(): ?Authorizable
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable ? $user : null;
    }
}
