<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Actions\ResetCustomerPassword;
use Modules\Identity\Mail\CustomerPasswordResetMail;

/*
| Customer account (spec §6.2): profile, password change, password recovery without account
| enumeration, links only to the brand's own storefront.
*/

beforeEach(function (): void {
    $this->api = 'http://localhost/api/v1';
    $this->email = 'acct-'.Str::lower(Str::random(8)).'@example.test';

    $this->postJson($this->api.'/auth/register', ['email' => $this->email, 'password' => 'Sufficiently-long-9', 'first_name' => 'Zara'])->assertCreated();
    $this->token = (string) $this->postJson($this->api.'/auth/login', ['email' => $this->email, 'password' => 'Sufficiently-long-9'])->json('data.token');
    app('auth')->forgetGuards();
});

it('reads and updates the profile', function (): void {
    $this->withToken($this->token)->getJson($this->api.'/account/profile')->assertOk()->assertJsonPath('data.email', $this->email);

    $this->withToken($this->token)->patchJson($this->api.'/account/profile', [
        'first_name' => 'Zara', 'last_name' => 'Ahmed', 'phone' => '+92 321 7654321', 'accepts_marketing' => true,
    ])->assertOk()
        ->assertJsonPath('data.last_name', 'Ahmed')
        ->assertJsonPath('data.accepts_marketing', true)
        ->assertJsonPath('data.email', $this->email);
});

it('changes the password only with the current one, keeping this session', function (): void {
    $this->withToken($this->token)->putJson($this->api.'/account/password', ['current_password' => 'wrong', 'password' => 'Another-long-pass-7'])
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('current_password', 'error.details');

    $this->withToken($this->token)->putJson($this->api.'/account/password', ['current_password' => 'Sufficiently-long-9', 'password' => 'Another-long-pass-7'])
        ->assertNoContent();

    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson($this->api.'/account/profile')->assertOk();
    $this->postJson($this->api.'/auth/login', ['email' => $this->email, 'password' => 'Another-long-pass-7'])->assertOk();
});

it('recovers a password with an emailed single-use link to the brand storefront', function (): void {
    Mail::fake();

    $this->postJson($this->api.'/auth/forgot-password', ['email' => strtoupper($this->email)])->assertStatus(202);
    // Unknown email: identical answer, nothing sent.
    $this->postJson($this->api.'/auth/forgot-password', ['email' => 'ghost-'.Str::random(6).'@example.test'])->assertStatus(202);

    $link = null;
    Mail::assertQueued(CustomerPasswordResetMail::class, function (CustomerPasswordResetMail $mail) use (&$link): bool {
        $link = $mail->link;

        return $mail->hasTo($this->email);
    });
    Mail::assertQueuedCount(1);

    expect($link)->toStartWith('http://store.test/account/reset-password?');
    parse_str((string) parse_url((string) $link, PHP_URL_QUERY), $query);

    // The auth limiter allows 5 requests a minute per IP; continue from another address.
    $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40']);

    $this->postJson($this->api.'/auth/reset-password', ['email' => $this->email, 'token' => str_repeat('x', 64), 'password' => 'Brand-new-pass-55'])
        ->assertUnprocessable();

    $this->postJson($this->api.'/auth/reset-password', ['email' => $this->email, 'token' => $query['token'], 'password' => 'Brand-new-pass-55'])
        ->assertNoContent();

    // Single use (checked through the Action: this account has used its 5 auth requests for
    // the minute), and every old sign-in is gone.
    expect(fn () => app(ResetCustomerPassword::class)->handle($this->email, (string) $query['token'], 'Brand-new-pass-56'))->toThrow(ValidationException::class);
    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson($this->api.'/account/profile')->assertUnauthorized();
});
