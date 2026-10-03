<?php

declare(strict_types=1);

use Tests\Support\TestCatalog;

/*
| api-conventions §3, §7, §8 enforced by infrastructure: private by default, ETags on public
| reads, rate limits with Retry-After, one error envelope.
*/

beforeEach(function (): void {
    $this->api = 'http://localhost/api/v1';
});

it('never lets a cart, an error or an account response into a shared cache', function (): void {
    $this->postJson($this->api.'/carts')->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson($this->api.'/products/01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson($this->api.'/account/orders')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
});

it('gives public reads an ETag and answers a matching If-None-Match with 304', function (): void {
    $product = TestCatalog::simpleProduct(publish: true);

    $first = $this->getJson($this->api.'/products/'.$product->ulid)->assertOk();
    $etag = (string) $first->headers->get('ETag');

    expect($etag)->toStartWith('W/"')
        ->and((string) $first->headers->get('Cache-Control'))->toContain('public');

    $this->withHeader('If-None-Match', $etag)->getJson($this->api.'/products/'.$product->ulid)
        ->assertStatus(304)
        ->assertNoContent(304);
});

it('rate limits sign-in per account with Retry-After and the standard envelope', function (): void {
    $email = 'nobody-'.bin2hex(random_bytes(4)).'@example.test';

    foreach (range(1, 5) as $attempt) {
        $this->postJson($this->api.'/auth/login', ['email' => $email, 'password' => 'wrong-password-'.$attempt])->assertStatus(422);
    }

    $this->postJson($this->api.'/auth/login', ['email' => $email, 'password' => 'wrong-password-6'])
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error.code', 'rate_limited');
});

it('refuses unknown filters and sorts instead of ignoring them', function (): void {
    $this->getJson($this->api.'/products?filter[colour]=red')->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    $this->getJson($this->api.'/products?sort=price')->assertUnprocessable();
});
