<?php

declare(strict_types=1);

namespace Modules\Identity\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\Customer;
use Modules\Identity\Support\PasswordPolicy;
use Modules\Support\Contracts\Action;

/**
 * Completes a reset: the token must match, be unused and unexpired. The new password obeys the
 * same policy as registration, and every existing sign-in is revoked — whoever held the old
 * password is logged out everywhere.
 */
final class ResetCustomerPassword implements Action
{
    public function __construct(
        private readonly PasswordPolicy $passwords,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(string $email, string $token, string $password): Customer
    {
        $invalid = ValidationException::withMessages(['token' => __('This reset link is invalid or has expired.')]);

        return $this->db->connection()->transaction(function () use ($email, $token, $password, $invalid): Customer {
            $customer = Customer::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first() ?? throw $invalid;

            $reset = $this->db->connection()->table('customer_password_resets')->where('customer_id', $customer->id)->lockForUpdate()->first();

            if ($reset === null || ! hash_equals((string) $reset->token_hash, hash('sha256', $token)) || Carbon::parse((string) $reset->expires_at)->isPast()) {
                throw $invalid;
            }

            $this->passwords->assertAcceptable($password, 'password');

            $customer->forceFill(['password' => $password])->save();
            $customer->tokens()->delete();
            $this->db->connection()->table('customer_password_resets')->where('customer_id', $customer->id)->delete();

            return $customer;
        });
    }
}
