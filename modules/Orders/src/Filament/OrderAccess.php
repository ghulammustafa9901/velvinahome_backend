<?php

declare(strict_types=1);

namespace Modules\Orders\Filament;

use Illuminate\Contracts\Auth\Access\Authorizable;

final class OrderAccess
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
