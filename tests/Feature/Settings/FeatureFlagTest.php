<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Settings\Contracts\FeatureFlags;
use Modules\Settings\Support\SettingsFeatureFlags;
use Tests\Support\TestSettings;

/*
| Feature flags are store settings (`features.*`): defaults for a new store, switched in the
| admin; a gated endpoint answers 403 feature_disabled.
*/

afterEach(function (): void {
    foreach (['wishlist', 'reviews'] as $flag) {
        TestSettings::feature($flag, false);
    }
});

it('starts a store with the default flags and reports every known flag', function (): void {
    $flags = app(FeatureFlags::class)->all();

    expect($flags)->toBe(collect(SettingsFeatureFlags::DEFAULTS)->sortKeys()->all());
});

it('follows the setting when a flag is switched on or off', function (): void {
    TestSettings::feature('reviews', true);

    expect(app(FeatureFlags::class)->enabled('reviews'))->toBeTrue();

    TestSettings::feature('reviews', false);

    expect(app(FeatureFlags::class)->enabled('reviews'))->toBeFalse()
        ->and(app(FeatureFlags::class)->enabled('no-such-flag'))->toBeFalse();
});

it('publishes the flags in the storefront config', function (): void {
    TestSettings::feature('wishlist', true);

    $this->getJson('http://localhost/api/v1/storefront/config')
        ->assertJsonPath('data.features.wishlist', true)
        ->assertJsonPath('data.features.blog', false);
});

it('answers 403 feature_disabled on a gated route', function (): void {
    Route::middleware(['api', 'feature:wishlist'])
        ->get('api/v1/test-wishlist', fn () => ['ok' => true]);

    $this->getJson('http://localhost/api/v1/test-wishlist')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'feature_disabled');

    TestSettings::feature('wishlist', true);

    $this->getJson('http://localhost/api/v1/test-wishlist')->assertOk();
});
