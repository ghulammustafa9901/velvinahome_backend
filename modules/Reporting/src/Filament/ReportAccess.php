<?php

declare(strict_types=1);

namespace Modules\Reporting\Filament;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Support\ValueObjects\Money;

final class ReportAccess
{
    public static function allowed(): bool
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable && $user->can('reports.view');
    }

    public static function money(int $amount, string $currency): string
    {
        return Money::of($amount, $currency)->format();
    }
}
