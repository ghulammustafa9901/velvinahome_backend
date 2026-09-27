<?php

declare(strict_types=1);

use Modules\Payments\Models\PaymentMethod;
use Tests\Support\TestPlatform;

/*
| Payments at the API edge: only enabled methods are offered, and with no online gateway
| configured (owner decision for launch) every webhook provider is unknown.
*/

beforeEach(function (): void {
    $this->api = 'http://'.TestPlatform::apiHost(TestPlatform::BRAND_A).'/api/v1';
});

it('lists only enabled payment methods and never their credentials', function (): void {
    $response = $this->getJson($this->api.'/payment-methods')->assertOk();
    $codes = collect($response->json('data'))->pluck('code')->all();

    $enabled = TestPlatform::brand(TestPlatform::BRAND_A)->run(
        fn () => PaymentMethod::query()->where('is_enabled', true)->orderBy('position')->pluck('code')->all(),
    );

    expect($codes)->toBe($enabled)
        ->and($codes)->toContain('cod')
        ->and(json_encode($response->json()))->not->toContain('credentials');
});

it('answers 404 for webhooks from a provider that is not configured', function (): void {
    $this->postJson($this->api.'/webhooks/payfast', ['event' => 'payment.succeeded'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});
