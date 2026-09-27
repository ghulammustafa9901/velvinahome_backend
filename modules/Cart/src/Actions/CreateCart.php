<?php

declare(strict_types=1);

namespace Modules\Cart\Actions;

use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Settings\Contracts\TenantSettings;
use Modules\Support\Contracts\Action;

/**
 * A signed-in customer gets (or reuses) their one active cart. A guest gets a new cart and
 * a secret token, returned once; the storefront BFF keeps it in an httpOnly cookie.
 */
final class CreateCart implements Action
{
    public const GUEST_TTL_DAYS = 30;

    public function __construct(private readonly TenantSettings $settings) {}

    /**
     * @return array{cart: Cart, token: string|null}
     */
    public function handle(?int $customerId): array
    {
        if ($customerId !== null) {
            $existing = Cart::query()->where('customer_id', $customerId)->where('status', 'active')->latest('id')->first();

            if ($existing !== null) {
                return ['cart' => $existing, 'token' => null];
            }
        }

        $token = $customerId === null ? Str::random(48) : null;

        $cart = Cart::query()->create([
            'customer_id' => $customerId,
            'token_hash' => $token === null ? null : hash('sha256', $token),
            'currency' => $this->settings->string('store.currency', 'PKR'),
            'status' => 'active',
            'expires_at' => now()->addDays(self::GUEST_TTL_DAYS),
        ]);

        return ['cart' => $cart, 'token' => $token];
    }
}
