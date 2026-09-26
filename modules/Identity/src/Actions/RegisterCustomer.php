<?php

declare(strict_types=1);

namespace Modules\Identity\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Identity\Data\CustomerRegistrationData;
use Modules\Identity\Models\Customer;
use Modules\Identity\Support\PasswordPolicy;
use Modules\Support\Contracts\Action;

/**
 * Creates a customer account in the current brand, or claims a guest record (a customer
 * row without a password, left by guest checkout) with the same email.
 */
final class RegisterCustomer implements Action
{
    public function __construct(private readonly PasswordPolicy $passwords) {}

    public function handle(CustomerRegistrationData $data): Customer
    {
        $this->passwords->assertAcceptable($data->password, 'password');

        $email = strtolower($data->email);
        $existing = Customer::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($existing !== null && $existing->password !== null) {
            throw ValidationException::withMessages(['email' => __('An account with this email already exists.')]);
        }

        $customer = $existing ?? new Customer(['email' => $email]);

        $customer->fill([
            'password' => $data->password,
            'first_name' => $data->firstName ?? $customer->first_name,
            'last_name' => $data->lastName ?? $customer->last_name,
            'phone' => $data->phone ?? $customer->phone,
            'accepts_marketing' => $data->acceptsMarketing,
        ])->save();

        return $customer;
    }
}
