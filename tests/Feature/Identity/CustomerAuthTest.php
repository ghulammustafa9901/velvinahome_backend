<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Identity\Models\Customer;
use Modules\Identity\Models\LoginAudit;

/*
| Spec §6.2: customers authenticate with Sanctum tokens (held by the BFF); every attempt is
| audited; failures are throttled per account and per IP; Argon2id hashing.
*/

beforeEach(function (): void {
    $this->api = 'http://localhost/api/v1';
    $this->email = 'cust-'.Str::lower(Str::random(10)).'@example.test';
});

function registerCustomer(object $test, string $password = 'Sufficiently-long-9'): void
{
    $test->postJson($test->api.'/auth/register', [
        'email' => $test->email,
        'password' => $password,
        'first_name' => 'Ayesha',
    ])->assertCreated()
        ->assertJsonPath('data.email', $test->email)
        ->assertJsonMissingPath('data.password');
}

it('registers, signs in, reads the profile and signs out', function (): void {
    registerCustomer($this);

    $token = $this->postJson($this->api.'/auth/login', ['email' => $this->email, 'password' => 'Sufficiently-long-9'])
        ->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->json('data.token');

    $profile = $this->withToken($token)->getJson($this->api.'/me')->assertOk();

    expect($profile->json('data.id'))->toHaveLength(26)
        ->and($profile->json('data'))->not->toHaveKey('password');

    $this->withToken($token)->postJson($this->api.'/auth/logout')->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson($this->api.'/me')->assertUnauthorized();
});

it('hashes customer passwords with Argon2id', function (): void {
    registerCustomer($this);

    $hash = Customer::query()->where('email', $this->email)->value('password');

    expect($hash)->toStartWith('$argon2id$')
        ->and(Hash::info((string) $hash)['algoName'])->toBe('argon2id');
});

it('rejects a weak password at registration', function (): void {
    $this->postJson($this->api.'/auth/register', ['email' => $this->email, 'password' => 'short'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password', 'error.details')->assertJsonPath('error.code', 'validation_failed');
});

it('refuses a duplicate registration', function (): void {
    registerCustomer($this);

    $this->postJson($this->api.'/auth/register', ['email' => strtoupper($this->email), 'password' => 'Sufficiently-long-9'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email', 'error.details');
});

it('gives the same answer for a wrong password and an unknown account, and audits both', function (): void {
    registerCustomer($this);

    $wrongPassword = $this->postJson($this->api.'/auth/login', ['email' => $this->email, 'password' => 'Wrong-password-99']);
    $unknown = $this->postJson($this->api.'/auth/login', ['email' => 'nobody-'.$this->email, 'password' => 'Wrong-password-99']);

    $wrongPassword->assertUnprocessable();
    $unknown->assertUnprocessable();

    expect($wrongPassword->json('error.details.email'))->toBe($unknown->json('error.details.email'));

    $reasons = LoginAudit::query()
        ->whereIn('email_attempted', [$this->email, 'nobody-'.$this->email])
        ->orderBy('id')
        ->pluck('failure_reason')
        ->all();

    expect($reasons)->toBe(['invalid_password', 'unknown_account']);
});

it('locks an account after repeated failures, even with the right password', function (): void {
    registerCustomer($this);
    // This test is about the account lockout; the route limiter (5 auth requests a minute,
    // which registration also counts against) is covered in ApiConventionsTest.
    app('cache')->store()->flush();

    foreach (range(1, (int) config('store.staff.max_login_attempts')) as $attempt) {
        $this->postJson($this->api.'/auth/login', ['email' => $this->email, 'password' => 'Wrong-password-99'])
            ->assertUnprocessable();
    }

    $this->postJson($this->api.'/auth/login', ['email' => $this->email, 'password' => 'Sufficiently-long-9'])
        ->assertStatus(429);
});

it('requires authentication for the profile', function (): void {
    $this->getJson($this->api.'/me')->assertUnauthorized();
});
