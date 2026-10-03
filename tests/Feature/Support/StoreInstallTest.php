<?php

declare(strict_types=1);

use Modules\Identity\Models\StaffUser;
use Modules\Settings\Contracts\StoreSettings;
use Tests\Support\TestSettings;

/*
| `store:install` sets an installation up as one client's store: identity in settings, the
| owner account, search, checklist. Idempotent; invalid options change nothing.
*/

const INSTALL_KEYS = ['store.name', 'store.url', 'store.timezone', 'store.locale', 'store.country', 'theme.id', 'store.currency'];

beforeEach(function (): void {
    $this->snapshot = collect(INSTALL_KEYS)->mapWithKeys(fn (string $key): array => [$key => app(StoreSettings::class)->get($key)])->all();
    $this->email = 'owner-'.uniqid().'@example.test';
});

afterEach(function (): void {
    foreach ($this->snapshot as $key => $value) {
        TestSettings::set($key, $value);
    }
});

it('writes the store identity and creates the owner once', function (): void {
    $this->artisan('store:install', [
        '--skip-migrate' => true,
        '--name' => 'Installed Store',
        '--url' => 'https://installed.example.test/',
        '--timezone' => 'Asia/Dubai',
        '--theme' => 'velvina-home',
        '--owner-email' => $this->email,
        '--owner-name' => 'Ayesha Khan',
    ])->expectsOutputToContain('One-time password')->expectsOutputToContain('Launch checklist')->assertSuccessful();

    $settings = app(StoreSettings::class);
    $owner = StaffUser::query()->where('email', $this->email)->firstOrFail();

    expect($settings->string('store.name'))->toBe('Installed Store')
        ->and($settings->storefrontUrl())->toBe('https://installed.example.test')
        ->and($settings->string('store.timezone'))->toBe('Asia/Dubai')
        ->and($settings->string('theme.id'))->toBe('velvina-home')
        ->and($owner->hasRole('Owner'))->toBeTrue();

    // A second run changes nothing it was not asked to, and never prints a password again.
    $this->artisan('store:install', ['--skip-migrate' => true, '--owner-email' => $this->email])
        ->doesntExpectOutputToContain('One-time password')
        ->assertSuccessful();

    expect(app(StoreSettings::class)->string('store.name'))->toBe('Installed Store')
        ->and(StaffUser::query()->where('email', $this->email)->count())->toBe(1);
});

it('refuses invalid options and changes nothing', function (array $options): void {
    $before = app(StoreSettings::class)->string('store.name');

    $this->artisan('store:install', ['--skip-migrate' => true, '--name' => 'Should Not Stick', ...$options])->assertFailed();

    expect(app(StoreSettings::class)->string('store.name'))->toBe($before);
})->with([
    'relative URL' => [['--url' => 'installed.example.test']],
    'unknown timezone' => [['--timezone' => 'Mars/Olympus']],
    'bad theme id' => [['--theme' => '../evil']],
    'unsupported currency' => [['--currency' => 'XYZ']],
    'bad owner email' => [['--owner-email' => 'not-an-email']],
]);

it('keeps the currency once the catalog has prices', function (): void {
    Tests\Support\TestCatalog::simpleProduct();

    $this->artisan('store:install', ['--skip-migrate' => true, '--currency' => 'USD'])
        ->expectsOutputToContain('fixed once the catalog has prices')
        ->assertFailed();

    expect(app(StoreSettings::class)->string('store.currency'))->toBe('PKR');
});
