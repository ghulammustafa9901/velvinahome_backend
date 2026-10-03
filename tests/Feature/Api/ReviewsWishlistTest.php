<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Orders\Actions\ConfirmCodOrder;
use Modules\Orders\Actions\CreateShipment;
use Modules\Orders\Actions\UpdateShipment;
use Modules\Reviews\Actions\ModerateReview;
use Modules\Reviews\Models\Review;
use Tests\Support\TestCommerce;
use Tests\Support\TestPlatform;
use Tests\Support\TestSettings;

/*
| Flag-gated storefront features (spec §5): reviews from verified buyers after moderation;
| wishlists. Off → 403 feature_disabled, never silently missing.
*/

beforeEach(function (): void {
    Mail::fake();
    $this->brand = TestPlatform::brand(TestPlatform::BRAND_A);
    $this->api = 'http://'.TestPlatform::apiHost(TestPlatform::BRAND_A).'/api/v1';
});

afterEach(function (): void {
    TestSettings::feature($this->brand, 'reviews', false);
    TestSettings::feature($this->brand, 'wishlist', false);
});

function enableFlag(object $test, string $key): void
{
    TestSettings::feature($test->brand, $key, true);
}

/**
 * @return array{0: string, 1: string} customer email, bearer token
 */
function signedInCustomer(object $test): array
{
    $email = 'rv-'.Str::lower(Str::random(8)).'@example.test';
    $test->postJson($test->api.'/auth/register', ['email' => $email, 'password' => 'Sufficiently-long-9', 'first_name' => 'Maham', 'last_name' => 'Tariq'])->assertCreated();
    $token = (string) $test->postJson($test->api.'/auth/login', ['email' => $email, 'password' => 'Sufficiently-long-9'])->json('data.token');
    app('auth')->forgetGuards();

    return [$email, $token];
}

it('answers 403 feature_disabled while the flags are off', function (): void {
    $this->getJson($this->api.'/products/01HZZZZZZZZZZZZZZZZZZZZZZZ/reviews')->assertForbidden()->assertJsonPath('error.code', 'feature_disabled');
    $this->getJson($this->api.'/wishlist/items')->assertForbidden()->assertJsonPath('error.code', 'feature_disabled');
});

it('accepts a review only from a customer who received the product, public after approval', function (): void {
    enableFlag($this, 'reviews');
    [, $token] = signedInCustomer($this);
    [$variant, $productUlid] = $this->brand->run(function (): array {
        $variant = TestCommerce::stockedVariant(100_000, 5);

        return [$variant, $variant->product->ulid];
    });
    $body = ['rating' => 4, 'title' => 'Lovely', 'body' => 'Soft fabric and quick delivery.'];

    $this->withToken($token)->postJson($this->api.'/products/'.$productUlid.'/reviews', $body)->assertUnprocessable();

    // Buy it and receive it.
    $cart = (string) $this->withToken($token)->postJson($this->api.'/carts')->json('data.id');
    $this->withToken($token)->postJson($this->api.'/carts/'.$cart.'/items', ['variant_id' => $variant->ulid, 'quantity' => 1])->assertOk();
    $orderId = (string) $this->withToken($token)->withHeader('Idempotency-Key', TestCommerce::idempotencyKey())
        ->postJson($this->api.'/checkout/orders', [
            'cart_id' => $cart, 'shipping_address' => TestCommerce::address(), 'shipping_method' => 'standard',
            'payment_method' => 'cod', 'expected_total' => 125_000,
        ])->assertCreated()->json('data.order_id');

    $this->brand->run(function () use ($orderId): void {
        app(ConfirmCodOrder::class)->handle($orderId, 'confirmed');
        $shipment = app(CreateShipment::class)->handle($orderId, null, null, null);
        app(UpdateShipment::class)->handle($shipment->ulid, 'delivered');
    });

    $this->withToken($token)->postJson($this->api.'/products/'.$productUlid.'/reviews', $body)
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.verified_purchase', true)
        ->assertJsonPath('data.author_name', 'Maham T');

    $this->flushHeaders()->getJson($this->api.'/products/'.$productUlid.'/reviews')->assertOk()->assertJsonCount(0, 'data');

    $this->brand->run(fn () => app(ModerateReview::class)->handle(Review::query()->where('product_ulid', $productUlid)->firstOrFail(), 'approved'));

    $this->getJson($this->api.'/products/'.$productUlid.'/reviews')
        ->assertOk()
        ->assertJsonPath('data.0.rating', 4)
        ->assertJsonPath('meta.summary.count', 1)
        ->assertJsonPath('meta.summary.average', 4)
        ->assertJsonPath('meta.summary.distribution.1', ['rating' => 4, 'count' => 1]);
});

it('keeps a private wishlist per customer, idempotent to add', function (): void {
    enableFlag($this, 'wishlist');
    [, $owner] = signedInCustomer($this);
    [, $other] = signedInCustomer($this);
    $product = $this->brand->run(fn () => TestCommerce::stockedVariant(100_000, 1)->product);

    $first = $this->withToken($owner)->postJson($this->api.'/wishlist/items', ['product_id' => $product->ulid])->assertCreated();
    $this->withToken($owner)->postJson($this->api.'/wishlist/items', ['product_id' => $product->ulid])->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

    $this->withToken($owner)->getJson($this->api.'/wishlist/items')->assertOk()->assertJsonCount(1, 'data');
    // Product cards for the wishlist, with live prices.
    $this->flushHeaders()->getJson($this->api.'/products?filter[ids]='.$product->ulid)->assertOk()->assertJsonPath('data.0.id', $product->ulid);

    app('auth')->forgetGuards();
    $this->withToken($other)->getJson($this->api.'/wishlist/items')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($other)->deleteJson($this->api.'/wishlist/items/'.$first->json('data.id'))->assertNotFound();

    app('auth')->forgetGuards();
    $this->withToken($owner)->deleteJson($this->api.'/wishlist/items/'.$first->json('data.id'))->assertNoContent();
});
