<?php

declare(strict_types=1);

namespace Modules\Identity\Actions;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Data\CustomerLoginData;
use Modules\Identity\Data\RequestContextData;
use Modules\Identity\Models\Customer;
use Modules\Identity\Models\LoginAudit;
use Modules\Identity\Support\LoginThrottle;
use Modules\Support\Contracts\Action;

/**
 * Exchanges customer credentials for a Sanctum token scoped to the current brand. The
 * storefront BFF stores it in an httpOnly cookie; browser JavaScript never sees it
 * (ADR-0003).
 *
 * Every attempt is audited; failures are throttled per account and per IP.
 */
final class AuthenticateCustomer implements Action
{
    public const ABILITIES = ['customer'];

    public function __construct(
        private readonly LoginThrottle $throttle,
        private readonly Hasher $hasher,
    ) {}

    /**
     * @return array{customer: Customer, token: string}
     */
    public function handle(CustomerLoginData $data, RequestContextData $context): array
    {
        $email = strtolower($data->email);

        if ($this->throttle->isLockedOut('customer', $email, $context->ip)) {
            $this->audit($email, null, false, 'locked_out', $context);

            throw ValidationException::withMessages([
                'email' => __('Too many sign-in attempts. Try again in :seconds seconds.', [
                    'seconds' => $this->throttle->secondsUntilAvailable('customer', $email),
                ]),
            ])->status(429);
        }

        $customer = Customer::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($customer === null || $customer->password === null || ! $this->hasher->check($data->password, $customer->password)) {
            $this->throttle->recordFailure('customer', $email, $context->ip);
            $this->audit($email, $customer?->id, false, $customer === null ? 'unknown_account' : 'invalid_password', $context);

            // One message for every failure: the response never reveals whether the account
            // exists.
            throw ValidationException::withMessages(['email' => __('These credentials do not match our records.')]);
        }

        $this->throttle->clear('customer', $email);
        $this->audit($email, $customer->id, true, null, $context);

        $customer->forceFill(['last_login_at' => now()])->save();

        $token = $customer->createToken($data->deviceName, self::ABILITIES, now()->addDays(30));

        return ['customer' => $customer, 'token' => $token->plainTextToken];
    }

    private function audit(string $email, ?int $customerId, bool $successful, ?string $reason, RequestContextData $context): void
    {
        LoginAudit::query()->create([
            'subject_type' => 'customer',
            'email_attempted' => mb_substr($email, 0, 255),
            'user_id' => $customerId,
            'successful' => $successful,
            'failure_reason' => $reason,
            'ip' => $context->ip,
            'user_agent' => $context->userAgent === null ? null : mb_substr($context->userAgent, 0, 1000),
        ]);
    }
}
