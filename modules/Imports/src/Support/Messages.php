<?php

declare(strict_types=1);

namespace Modules\Imports\Support;

use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A readable one-line reason for an import report.
 */
final class Messages
{
    public static function of(Throwable $e): string
    {
        return $e instanceof ValidationException
            ? implode(' ', array_map(fn (array $messages): string => implode(' ', $messages), $e->errors()))
            : $e->getMessage();
    }
}
