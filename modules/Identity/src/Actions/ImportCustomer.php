<?php

declare(strict_types=1);

namespace Modules\Identity\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\Customer;
use Modules\Support\Contracts\Action;

/**
 * A migrated customer record: see CustomerImport for the rules.
 */
final class ImportCustomer implements Action
{
    public function handle(string $email, ?string $firstName, ?string $lastName, ?string $phone): Customer
    {
        $email = mb_strtolower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255) {
            throw ValidationException::withMessages(['email' => __('Not a valid email address.')]);
        }

        $clean = fn (?string $value, int $max): ?string => ($value = trim((string) $value)) === '' ? null : mb_substr($value, 0, $max);

        $customer = Customer::query()->whereRaw('lower(email) = ?', [$email])->first() ?? new Customer(['email' => $email]);

        $customer->forceFill([
            'first_name' => $customer->first_name ?? $clean($firstName, 100),
            'last_name' => $customer->last_name ?? $clean($lastName, 100),
            'phone' => $customer->phone ?? $clean($phone, 32),
        ]);

        if (! $customer->exists) {
            $customer->forceFill(['password' => null, 'accepts_marketing' => false]);
        }

        $customer->save();

        return $customer;
    }
}
