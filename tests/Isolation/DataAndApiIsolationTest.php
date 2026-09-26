<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Modules\Identity\Models\Customer;
use Modules\Settings\Actions\UpdateSetting;
use Tests\Support\TestPlatform;

/*
| Isolation channels 1 (database), 2 (API) and 9 (two brands of one company).
| Brands B and C belong to the same company; A belongs to another.
*/

it('keeps rows written in one brand out of every other brand', function (): void {
    $email = 'iso-'.Str::lower(Str::random(10)).'@example.test';

    TestPlatform::brand(TestPlatform::BRAND_B)->run(fn () => Customer::query()->create(['email' => $email]));

    foreach ([TestPlatform::BRAND_A, TestPlatform::BRAND_C] as $other) {
        $found = TestPlatform::brand($other)->run(fn (): bool => Customer::query()->where('email', $email)->exists());

        expect($found)->toBeFalse("Customer written in brand B is visible in {$other}");
    }

    expect(TestPlatform::brand(TestPlatform::BRAND_B)->run(fn (): bool => Customer::query()->where('email', $email)->exists()))->toBeTrue();
});

it('lets two brands of one company hold the same email independently', function (): void {
    $email = 'same-'.Str::lower(Str::random(10)).'@example.test';

    $b = TestPlatform::brand(TestPlatform::BRAND_B)->run(fn () => Customer::query()->create(['email' => $email, 'first_name' => 'B']));
    $c = TestPlatform::brand(TestPlatform::BRAND_C)->run(fn () => Customer::query()->create(['email' => $email, 'first_name' => 'C']));

    expect($b->ulid)->not->toBe($c->ulid);
});

it('rejects a customer token from one brand on a sibling brand\'s API', function (): void {
    $email = 'token-'.Str::lower(Str::random(10)).'@example.test';

    $this->postJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1/auth/register', [
        'email' => $email, 'password' => 'Sufficiently-long-9',
    ])->assertCreated();

    $token = $this->postJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1/auth/login', [
        'email' => $email, 'password' => 'Sufficiently-long-9',
    ])->assertOk()->json('data.token');

    $this->withToken($token)->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', $email);

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_C).'/api/v1/me')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_A).'/api/v1/me')->assertUnauthorized();
});

it('keeps settings of one brand from changing its sibling brand', function (): void {
    $marker = 'iso-'.Str::lower(Str::random(8));

    TestPlatform::brand(TestPlatform::BRAND_B)->run(fn () => app(UpdateSetting::class)->handle('probe', $marker, 'brand-b'));

    $inC = TestPlatform::brand(TestPlatform::BRAND_C)->run(fn () => app(Modules\Settings\Contracts\TenantSettings::class)->get('probe.'.$marker));
    $inB = TestPlatform::brand(TestPlatform::BRAND_B)->run(fn () => app(Modules\Settings\Contracts\TenantSettings::class)->get('probe.'.$marker));

    expect($inC)->toBeNull()->and($inB)->toBe('brand-b');
});
