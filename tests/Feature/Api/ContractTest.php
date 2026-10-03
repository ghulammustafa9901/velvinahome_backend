<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Catalog\Actions\SaveCollection;
use Modules\Catalog\Actions\UpdateProductDetails;
use Modules\Catalog\Data\ProductDetailsData;
use Modules\Orders\Actions\ConfirmCodOrder;
use Modules\Orders\Actions\CreateShipment;
use Modules\Orders\Actions\UpdateShipment;
use Tests\Support\Contract;
use Tests\Support\TestCommerce;
use Tests\Support\TestSettings;

/*
| Contract tests (api-conventions §10): every endpoint's real responses — success and error —
| validate against the committed OpenAPI document, strictly (no undocumented fields). The
| generated TypeScript client is built from that document, so this is what keeps the client
| truthful.
*/

beforeEach(function (): void {
    Mail::fake();
    $this->api = 'http://localhost/api/v1';
});

afterEach(function (): void {
    TestSettings::feature('reviews', false);
    TestSettings::feature('wishlist', false);
});

/**
 * @return array{0: string, 1: string} email, token
 */
function contractCustomer(object $test): array
{
    $email = 'ct-'.Str::lower(Str::random(8)).'@example.test';
    Contract::assert($test->postJson($test->api.'/auth/register', ['email' => $email, 'password' => 'Sufficiently-long-9', 'first_name' => 'Ali', 'last_name' => 'Raza'])->assertCreated(), 'POST /v1/auth/register');
    $login = $test->postJson($test->api.'/auth/login', ['email' => $email, 'password' => 'Sufficiently-long-9'])->assertOk();
    Contract::assert($login, 'POST /v1/auth/login');
    app('auth')->forgetGuards();
    app('cache')->store()->flush();

    return [$email, (string) $login->json('data.token')];
}

it('matches the contract for catalog, content and discovery endpoints', function (): void {
    $variant = TestCommerce::stockedVariant(150_000, 4);
    $product = $variant->product;
    $category = app(Modules\Catalog\Actions\SaveCategory::class)->handle(null, 'Contract '.Str::random(5));
    app(UpdateProductDetails::class)->handle($product, new ProductDetailsData(title: $product->title, slug: $product->slug, categoryUlids: [$category->ulid]));
    $collection = app(SaveCollection::class)->handle(null, 'Picks '.Str::random(5), [$product->ulid]);
    [$product, $category, $collection] = [$product->refresh(), $category, $collection];
    Contract::assert($this->getJson($this->api.'/storefront/config')->assertOk(), 'GET /v1/storefront/config');
    Contract::assert($this->getJson($this->api.'/products?per_page=2')->assertOk(), 'GET /v1/products');
    Contract::assert($this->getJson($this->api.'/products?filter[colour]=red')->assertUnprocessable(), 'GET /v1/products');
    Contract::assert($this->getJson($this->api.'/products/'.$product->ulid)->assertOk(), 'GET /v1/products/{ulid}');
    Contract::assert($this->getJson($this->api.'/products/01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound(), 'GET /v1/products/{ulid}');
    Contract::assert($this->getJson($this->api.'/products/by-slug/'.$product->slug)->assertOk(), 'GET /v1/products/by-slug/{slug}');

    $old = $product->slug;
    app(UpdateProductDetails::class)->handle($product, new ProductDetailsData(title: $product->title, slug: $old.'-v2', categoryUlids: [$category->ulid]));
    Contract::assert($this->getJson($this->api.'/products/by-slug/'.$old)->assertStatus(301), 'GET /v1/products/by-slug/{slug}');
    Contract::assert($this->getJson($this->api.'/redirects/resolve?path=/products/'.$old)->assertOk(), 'GET /v1/redirects/resolve');

    Contract::assert($this->getJson($this->api.'/categories')->assertOk(), 'GET /v1/categories');
    Contract::assert($this->getJson($this->api.'/categories/'.$category->slug)->assertOk(), 'GET /v1/categories/{slug}');
    Contract::assert($this->getJson($this->api.'/collections/'.$collection->slug)->assertOk(), 'GET /v1/collections/{slug}');
    Contract::assert($this->getJson($this->api.'/search?q='.urlencode($product->title))->assertOk(), 'GET /v1/search');
    Contract::assert($this->getJson($this->api.'/search/suggest?q='.urlencode(Str::substr($product->title, 0, 4)))->assertOk(), 'GET /v1/search/suggest');
    Contract::assert($this->getJson($this->api.'/pages/home')->assertOk(), 'GET /v1/pages/{slug}');
    Contract::assert($this->getJson($this->api.'/menus/main')->assertOk(), 'GET /v1/menus/{key}');
    Contract::assert($this->getJson($this->api.'/sitemap')->assertOk(), 'GET /v1/sitemap');
    Contract::assert($this->getJson($this->api.'/sitemap/products-1')->assertOk(), 'GET /v1/sitemap/{chunk}');
    Contract::assert($this->getJson($this->api.'/payment-methods')->assertOk(), 'GET /v1/payment-methods');
});

it('matches the contract for carts and checkout, including conflicts and replays', function (): void {
    $variant = TestCommerce::stockedVariant(100_000, 3);
    $created = $this->postJson($this->api.'/carts')->assertCreated();
    Contract::assert($created, 'POST /v1/carts');
    $cart = (string) $created->json('data.id');
    $this->withHeader('X-Cart-Token', (string) $created->json('data.token'));

    Contract::assert($this->postJson($this->api.'/carts/'.$cart.'/items', ['variant_id' => $variant->ulid, 'quantity' => 9])->assertStatus(409), 'POST /v1/carts/{ulid}/items');
    $added = $this->postJson($this->api.'/carts/'.$cart.'/items', ['variant_id' => $variant->ulid, 'quantity' => 2])->assertOk();
    Contract::assert($added, 'POST /v1/carts/{ulid}/items');
    $line = (string) $added->json('data.lines.0.id');

    Contract::assert($this->patchJson($this->api.'/carts/'.$cart.'/items/'.$line, ['quantity' => 1])->assertOk(), 'PATCH /v1/carts/{ulid}/items/{itemUlid}');
    Contract::assert($this->getJson($this->api.'/carts/'.$cart)->assertOk(), 'GET /v1/carts/{ulid}');
    Contract::assert($this->postJson($this->api.'/carts/'.$cart.'/coupon', ['code' => 'NOPE-'.Str::random(4)])->assertUnprocessable(), 'POST /v1/carts/{ulid}/coupon');
    Contract::assert($this->deleteJson($this->api.'/carts/'.$cart.'/coupon')->assertOk(), 'DELETE /v1/carts/{ulid}/coupon');
    Contract::assert($this->getJson($this->api.'/checkout/'.$cart.'/shipping-methods?city=Lahore')->assertOk(), 'GET /v1/checkout/{cart}/shipping-methods');

    $address = TestCommerce::address();
    Contract::assert($this->postJson($this->api.'/checkout/quote', ['cart_id' => $cart, 'shipping_address' => $address, 'shipping_method' => 'standard'])->assertOk(), 'POST /v1/checkout/quote');

    $body = ['cart_id' => $cart, 'email' => 'ct@example.test', 'shipping_address' => $address, 'shipping_method' => 'standard', 'payment_method' => 'cod', 'expected_total' => 125_000];
    Contract::assert($this->postJson($this->api.'/checkout/orders', $body)->assertUnprocessable(), 'POST /v1/checkout/orders');

    $key = TestCommerce::idempotencyKey();
    Contract::assert($this->withHeader('Idempotency-Key', $key)->postJson($this->api.'/checkout/orders', [...$body, 'expected_total' => 1])->assertStatus(409), 'POST /v1/checkout/orders');
    $placed = $this->withHeader('Idempotency-Key', $key)->postJson($this->api.'/checkout/orders', $body)->assertCreated();
    Contract::assert($placed, 'POST /v1/checkout/orders');
    Contract::assert($this->withHeader('Idempotency-Key', $key)->postJson($this->api.'/checkout/orders', $body)->assertOk(), 'POST /v1/checkout/orders');

    $order = (string) $placed->json('data.order_id');
    $this->flushHeaders();
    Contract::assert($this->withHeader('X-Order-Token', (string) $placed->json('data.access_token'))->getJson($this->api.'/orders/'.$order)->assertOk(), 'GET /v1/orders/{ulid}');
    Contract::assert($this->flushHeaders()->getJson($this->api.'/orders/'.$order)->assertNotFound(), 'GET /v1/orders/{ulid}');
});

it('matches the contract for a transfer receipt upload', function (): void {
    TestCommerce::enableTransfers();
    $variant = TestCommerce::stockedVariant(100_000, 3);
    $created = $this->postJson($this->api.'/carts')->assertCreated();
    $cart = (string) $created->json('data.id');
    $this->withHeader('X-Cart-Token', (string) $created->json('data.token'));
    $this->postJson($this->api.'/carts/'.$cart.'/items', ['variant_id' => $variant->ulid, 'quantity' => 1])->assertOk();

    $placed = $this->withHeader('Idempotency-Key', TestCommerce::idempotencyKey())->postJson($this->api.'/checkout/orders', [
        'cart_id' => $cart, 'email' => 'tr@example.test', 'shipping_address' => TestCommerce::address(), 'shipping_method' => 'standard',
        'payment_method' => 'manual_transfer', 'expected_total' => 125_000,
    ])->assertCreated();

    $this->flushHeaders()->withHeader('X-Order-Token', (string) $placed->json('data.access_token'));
    Contract::assert(
        $this->post($this->api.'/orders/'.$placed->json('data.order_id').'/payment-proof', ['receipt' => UploadedFile::fake()->image('r.png')], ['Accept' => 'application/json'])->assertOk(),
        'POST /v1/orders/{ulid}/payment-proof',
    );
});

it('matches the contract for customer accounts', function (): void {
    [$email, $token] = contractCustomer($this);

    Contract::assert($this->postJson($this->api.'/auth/login', ['email' => $email, 'password' => 'wrong-'.Str::random(6)])->assertUnprocessable(), 'POST /v1/auth/login');
    Contract::assert($this->getJson($this->api.'/account/profile')->assertUnauthorized(), 'GET /v1/account/profile');

    $this->withToken($token);
    Contract::assert($this->getJson($this->api.'/me')->assertOk(), 'GET /v1/me');
    Contract::assert($this->getJson($this->api.'/account/profile')->assertOk(), 'GET /v1/account/profile');
    Contract::assert($this->patchJson($this->api.'/account/profile', ['first_name' => 'Ali', 'accepts_marketing' => true])->assertOk(), 'PATCH /v1/account/profile');

    $address = $this->postJson($this->api.'/account/addresses', TestCommerce::address() + ['label' => 'Home', 'is_default_shipping' => true])->assertCreated();
    Contract::assert($address, 'POST /v1/account/addresses');
    Contract::assert($this->putJson($this->api.'/account/addresses/'.$address->json('data.id'), TestCommerce::address(['city' => 'Karachi']))->assertOk(), 'PUT /v1/account/addresses/{ulid}');
    Contract::assert($this->getJson($this->api.'/account/addresses')->assertOk(), 'GET /v1/account/addresses');
    Contract::assert($this->deleteJson($this->api.'/account/addresses/'.$address->json('data.id'))->assertNoContent(), 'DELETE /v1/account/addresses/{ulid}');
    Contract::assert($this->getJson($this->api.'/account/orders')->assertOk(), 'GET /v1/account/orders');

    Contract::assert($this->putJson($this->api.'/account/password', ['current_password' => 'Sufficiently-long-9', 'password' => 'Another-long-pass-7'])->assertNoContent(), 'PUT /v1/account/password');
    Contract::assert($this->postJson($this->api.'/auth/logout')->assertNoContent(), 'POST /v1/auth/logout');

    $this->flushHeaders();
    Contract::assert($this->postJson($this->api.'/auth/forgot-password', ['email' => $email])->assertStatus(202), 'POST /v1/auth/forgot-password');
    Contract::assert($this->postJson($this->api.'/auth/reset-password', ['email' => $email, 'token' => str_repeat('a', 64), 'password' => 'Brand-new-pass-55'])->assertUnprocessable(), 'POST /v1/auth/reset-password');
});

it('matches the contract for flag-gated reviews and wishlist', function (): void {
    Contract::assert($this->getJson($this->api.'/wishlist/items')->assertForbidden(), 'GET /v1/wishlist/items');

    foreach (['reviews', 'wishlist'] as $flag) {
        TestSettings::feature($flag, true);
    }

    [, $token] = contractCustomer($this);
    [$variant, $productUlid] = [$v = TestCommerce::stockedVariant(100_000, 3), $v->product->ulid];

    $this->withToken($token);
    Contract::assert($this->postJson($this->api.'/wishlist/items', ['product_id' => $productUlid])->assertCreated(), 'POST /v1/wishlist/items');
    Contract::assert($this->postJson($this->api.'/wishlist/items', ['product_id' => $productUlid])->assertOk(), 'POST /v1/wishlist/items');
    $items = $this->getJson($this->api.'/wishlist/items')->assertOk();
    Contract::assert($items, 'GET /v1/wishlist/items');
    Contract::assert($this->deleteJson($this->api.'/wishlist/items/'.$items->json('data.0.id'))->assertNoContent(), 'DELETE /v1/wishlist/items/{ulid}');

    Contract::assert($this->postJson($this->api.'/products/'.$productUlid.'/reviews', ['rating' => 5, 'body' => 'Not bought yet, sadly.'])->assertUnprocessable(), 'POST /v1/products/{product}/reviews');

    $cart = (string) $this->postJson($this->api.'/carts')->json('data.id');
    $this->postJson($this->api.'/carts/'.$cart.'/items', ['variant_id' => $variant->ulid, 'quantity' => 1])->assertOk();
    $order = (string) $this->withHeader('Idempotency-Key', TestCommerce::idempotencyKey())->postJson($this->api.'/checkout/orders', [
        'cart_id' => $cart, 'shipping_address' => TestCommerce::address(), 'shipping_method' => 'standard', 'payment_method' => 'cod', 'expected_total' => 125_000,
    ])->assertCreated()->json('data.order_id');

    Contract::assert($this->getJson($this->api.'/account/orders/'.$order)->assertOk(), 'GET /v1/account/orders/{ulid}');
    app(ConfirmCodOrder::class)->handle($order, 'confirmed');
    app(UpdateShipment::class)->handle(app(CreateShipment::class)->handle($order, null, 'TCS', 'T-9')->ulid, 'delivered');

    Contract::assert($this->postJson($this->api.'/products/'.$productUlid.'/reviews', ['rating' => 5, 'title' => 'Great', 'body' => 'Exactly as described.'])->assertCreated(), 'POST /v1/products/{product}/reviews');
    Contract::assert($this->flushHeaders()->getJson($this->api.'/products/'.$productUlid.'/reviews')->assertOk(), 'GET /v1/products/{product}/reviews');
});

it('matches the contract for webhooks and rate limiting', function (): void {
    Contract::assert($this->postJson($this->api.'/webhooks/payfast', [])->assertNotFound(), 'POST /v1/webhooks/{provider}');

    foreach (range(1, 5) as $attempt) {
        $this->postJson($this->api.'/auth/login', ['email' => 'rl@example.test', 'password' => 'x'.$attempt]);
    }

    Contract::assert($this->postJson($this->api.'/auth/login', ['email' => 'rl@example.test', 'password' => 'x6'])->assertStatus(429), 'POST /v1/auth/login');
});
