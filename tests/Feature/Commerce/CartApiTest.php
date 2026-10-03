<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\TestCommerce;

/*
| Carts (spec §5, §8): guest carts are reachable only with their secret token, customer carts
| only with that customer's bearer token; prices are recomputed on every read.
*/

beforeEach(function (): void {
    $this->api = 'http://localhost/api/v1';
});

function customerToken(object $test): string
{
    $email = 'cust-'.Str::lower(Str::random(8)).'@example.test';
    $test->postJson($test->api.'/auth/register', ['email' => $email, 'password' => 'Sufficiently-long-9', 'first_name' => 'Hira'])->assertCreated();
    $token = (string) $test->postJson($test->api.'/auth/login', ['email' => $email, 'password' => 'Sufficiently-long-9'])->json('data.token');
    app('auth')->forgetGuards();

    return $token;
}

it('creates a guest cart whose token is shown once and required afterwards', function (): void {
    $variant = TestCommerce::stockedVariant(80_000, 3);

    $created = $this->postJson($this->api.'/carts')->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    $cart = (string) $created->json('data.id');
    $token = (string) $created->json('data.token');

    expect($token)->toHaveLength(48);

    $this->withHeader('X-Cart-Token', $token)
        ->postJson($this->api.'/carts/'.$cart.'/items', ['variant_id' => $variant->ulid, 'quantity' => 2])
        ->assertOk()
        ->assertJsonPath('data.item_count', 2)
        ->assertJsonPath('data.lines.0.unit_price.amount', 80_000)
        ->assertJsonPath('data.lines.0.available_quantity', 3)
        ->assertJsonPath('data.totals.subtotal.amount', 160_000)
        ->assertJsonPath('data.token', null);

    // Default headers persist between requests in a test; start clean.
    $this->flushHeaders()->getJson($this->api.'/carts/'.$cart)->assertNotFound();
    $this->withHeader('X-Cart-Token', Str::random(48))->getJson($this->api.'/carts/'.$cart)->assertNotFound();
});

it('refuses more than is in stock and removes a line set to zero', function (): void {
    $variant = TestCommerce::stockedVariant(80_000, 2);
    $created = $this->postJson($this->api.'/carts')->assertCreated();
    $cart = (string) $created->json('data.id');
    $this->withHeader('X-Cart-Token', (string) $created->json('data.token'));

    $this->postJson($this->api.'/carts/'.$cart.'/items', ['variant_id' => $variant->ulid, 'quantity' => 3])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'stock_unavailable');

    $line = (string) $this->postJson($this->api.'/carts/'.$cart.'/items', ['variant_id' => $variant->ulid, 'quantity' => 2])
        ->assertOk()->json('data.lines.0.id');

    $this->patchJson($this->api.'/carts/'.$cart.'/items/'.$line, ['quantity' => 0])
        ->assertOk()
        ->assertJsonPath('data.item_count', 0)
        ->assertJsonCount(0, 'data.lines');
});

it('keeps a customer cart private to that customer', function (): void {
    $variant = TestCommerce::stockedVariant(80_000, 5);
    $owner = customerToken($this);
    $intruder = customerToken($this);

    $cart = (string) $this->withToken($owner)->postJson($this->api.'/carts')->assertCreated()->assertJsonPath('data.token', null)->json('data.id');
    $this->withToken($owner)->postJson($this->api.'/carts/'.$cart.'/items', ['variant_id' => $variant->ulid, 'quantity' => 1])->assertOk();
    app('auth')->forgetGuards();

    $this->flushHeaders()->withToken($intruder)->getJson($this->api.'/carts/'.$cart)->assertNotFound();
    app('auth')->forgetGuards();
    $this->withToken($intruder)->postJson($this->api.'/carts/'.$cart.'/items', ['variant_id' => $variant->ulid, 'quantity' => 1])->assertNotFound();
});

it('merges a guest cart into the customer cart on sign-in', function (): void {
    [$a, $b] = [TestCommerce::stockedVariant(80_000, 9), TestCommerce::stockedVariant(40_000, 9)];
    $customer = customerToken($this);

    $mine = (string) $this->withToken($customer)->postJson($this->api.'/carts')->json('data.id');
    $this->withToken($customer)->postJson($this->api.'/carts/'.$mine.'/items', ['variant_id' => $a->ulid, 'quantity' => 1])->assertOk();
    app('auth')->forgetGuards();

    $guest = $this->flushHeaders()->postJson($this->api.'/carts')->assertCreated();
    $guestId = (string) $guest->json('data.id');
    $guestToken = (string) $guest->json('data.token');
    $this->withHeader('X-Cart-Token', $guestToken)->postJson($this->api.'/carts/'.$guestId.'/items', ['variant_id' => $a->ulid, 'quantity' => 2])->assertOk();
    $this->withHeader('X-Cart-Token', $guestToken)->postJson($this->api.'/carts/'.$guestId.'/items', ['variant_id' => $b->ulid, 'quantity' => 1])->assertOk();

    $merged = $this->withToken($customer)->withHeader('X-Cart-Token', $guestToken)
        ->postJson($this->api.'/carts/'.$guestId.'/merge')
        ->assertOk()
        ->assertJsonPath('data.id', $mine)
        ->assertJsonPath('data.item_count', 4);

    expect(collect($merged->json('data.lines'))->pluck('quantity', 'variant_id')->all())->toBe([$a->ulid => 3, $b->ulid => 1]);

    $this->withHeader('X-Cart-Token', $guestToken)->getJson($this->api.'/carts/'.$guestId)->assertNotFound();
});
