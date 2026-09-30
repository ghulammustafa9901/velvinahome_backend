<?php

declare(strict_types=1);

namespace Modules\Identity\Actions;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\Customer;
use Modules\Identity\Support\PasswordPolicy;
use Modules\Support\Contracts\Action;

/**
 * A signed-in customer changes their password. The current password is required; every other
 * sign-in (other devices) is revoked, the current one stays.
 */
final class ChangeCustomerPassword implements Action
{
    public function __construct(
        private readonly PasswordPolicy $passwords,
        private readonly Hasher $hasher,
    ) {}

    public function handle(Customer $customer, string $currentPassword, string $newPassword, ?int $keepTokenId): void
    {
        if ($customer->password === null || ! $this->hasher->check($currentPassword, $customer->password)) {
            throw ValidationException::withMessages(['current_password' => __('The current password is not correct.')]);
        }

        $this->passwords->assertAcceptable($newPassword, 'password');

        $customer->forceFill(['password' => $newPassword])->save();
        $customer->tokens()->when($keepTokenId !== null, fn ($query) => $query->whereKeyNot($keepTokenId))->delete();
    }
}
