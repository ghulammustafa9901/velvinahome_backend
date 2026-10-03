<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
| Spec §8: Cloudflare Turnstile on customer sign-in, registration and checkout — off until a
| secret key is configured.
*/

beforeEach(function (): void {
    $this->api = 'http://localhost/api/v1';
    $this->email = 'human-'.Str::lower(Str::random(10)).'@example.test';
    Cache::flush();
});

function attemptLogin(object $test, array $headers = []): Illuminate\Testing\TestResponse
{
    return $test->postJson($test->api.'/auth/login', ['email' => $test->email, 'password' => 'Wrong-password-123'], $headers);
}

it('does nothing while no secret key is configured', function (): void {
    Http::preventStrayRequests();
    config(['platform.turnstile.secret_key' => '']);

    attemptLogin($this)->assertStatus(422)->assertJsonMissingPath('error.details.turnstile');
});

it('refuses a missing or rejected token', function (): void {
    config(['platform.turnstile.secret_key' => 'test-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

    attemptLogin($this)->assertStatus(422)->assertJsonPath('error.details.turnstile.0', 'Please complete the security check and try again.');
    attemptLogin($this, ['X-Turnstile-Token' => 'forged'])->assertStatus(422)->assertJsonPath('error.details.turnstile.0', 'Please complete the security check and try again.');
});

it('verifies a token with Cloudflare and lets two follow-up calls through', function (): void {
    config(['platform.turnstile.secret_key' => 'test-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    attemptLogin($this, ['X-Turnstile-Token' => 'good-token'])->assertJsonMissingPath('error.details.turnstile');

    Http::assertSent(fn (ClientRequest $request): bool => $request['secret'] === 'test-secret' && $request['response'] === 'good-token');

    attemptLogin($this)->assertJsonMissingPath('error.details.turnstile');
    attemptLogin($this)->assertJsonMissingPath('error.details.turnstile');
    attemptLogin($this)->assertJsonPath('error.details.turnstile.0', 'Please complete the security check and try again.');
});

it('lets the request through when Cloudflare cannot be reached', function (): void {
    config(['platform.turnstile.secret_key' => 'test-secret']);
    Http::fake(fn () => throw new ConnectionException('timeout'));

    attemptLogin($this, ['X-Turnstile-Token' => 'any'])->assertJsonMissingPath('error.details.turnstile');
});
