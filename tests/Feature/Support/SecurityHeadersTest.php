<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\Support\TestPlatform;

/*
| Spec §8 headers on API, admin and platform responses; production errors leak nothing.
*/

it('sends security headers on API responses, with a deny-all CSP', function (): void {
    $response = $this->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1/storefront/config')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->has('X-Powered-By'))->toBeFalse()
        ->and($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('sends HSTS over HTTPS and frame protection on the admin and platform panels', function (string $host): void {
    $response = $this->get('https://'.$host.'/login')->assertOk();

    expect($response->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY');
})->with([TestPlatform::adminHost(TestPlatform::BRAND_B), 'platform.test']);

it('never shows exception details when debug is off', function (): void {
    config(['app.debug' => false]);
    Route::middleware(['tenant:api', 'api'])->get('api/v1/test-explode', fn () => throw new RuntimeException('secret internal detail at /srv/app/x.php'));

    $response = $this->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1/test-explode')->assertStatus(500);

    expect($response->getContent())->not->toContain('secret internal detail')
        ->not->toContain('x.php')
        ->not->toContain('trace')
        ->and($response->json('error.code'))->toBe('server_error')
        ->and($response->json('error.request_id'))->not->toBeNull();
});
