<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * One password policy for staff and customers: length, mixed characters, and a breached-
 * password check against Have I Been Pwned's k-anonymity API (spec §6.2). The breach check
 * is configurable only so the test suite does not call an external service.
 */
final class PasswordPolicy
{
    public function rule(): Password
    {
        $rule = Password::min(10)->letters()->numbers();

        return config('store.passwords.check_breached', true) ? $rule->uncompromised() : $rule;
    }

    public function assertAcceptable(string $password, string $field): void
    {
        $validator = validator([$field => $password], [$field => ['required', $this->rule()]]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
