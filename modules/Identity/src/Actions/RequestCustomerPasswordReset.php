<?php

declare(strict_types=1);

namespace Modules\Identity\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Identity\Mail\CustomerPasswordResetMail;
use Modules\Identity\Models\Customer;
use Modules\Settings\Contracts\StoreSettings;
use Modules\Support\Contracts\Action;

/**
 * "Forgot password". Always behaves the same whether or not the email has an account, so the
 * endpoint cannot be used to discover customers. The token is random, single-use, valid for an
 * hour, stored only as a hash, and the link points at the store URL setting — never at a URL
 * taken from the request.
 */
final class RequestCustomerPasswordReset implements Action
{
    public const TTL_MINUTES = 60;

    public function __construct(private readonly StoreSettings $settings) {}

    public function handle(string $email): void
    {
        $email = mb_strtolower(trim($email));
        $customer = Customer::query()->whereRaw('lower(email) = ?', [$email])->whereNotNull('password')->first();
        $storefront = $this->settings->storefrontUrl();

        if ($customer === null || $storefront === null) {
            return;
        }

        $token = Str::random(64);

        DB::table('customer_password_resets')->upsert([
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'created_at' => now(),
        ], ['customer_id'], ['token_hash', 'expires_at', 'created_at']);

        $link = $storefront.'/account/reset-password?'.http_build_query(['email' => $customer->email, 'token' => $token]);
        $store = $this->settings->string('mail.from_name') ?: $this->settings->string('store.name', 'Store');

        // Queued (never stored in the outbox table, which would keep the token in plain text),
        // and only after the reset row is committed.
        Mail::to($customer->email)->queue((new CustomerPasswordResetMail($link, $store, self::TTL_MINUTES))->afterCommit());
    }
}
