<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Platform\Contracts\FeatureFlags;
use Modules\Platform\Models\Plan;
use Modules\Platform\Models\TenantFeatureFlag;
use Tests\Support\TestPlatform;

/*
| Plan 1.7: effective flags = plan defaults overridden by the tenant's own rows; a gated
| endpoint answers 403 feature_disabled.
*/

afterEach(function (): void {
    $brandB = TestPlatform::brand(TestPlatform::BRAND_B);

    app('db')->connection('landlord')->table('tenant_feature_flags')->where('tenant_id', $brandB->id)->delete();
    $brandB->forceFill(['plan_id' => null])->save();
    app(FeatureFlags::class)->forget($brandB->id);
});

it('lets a tenant override its plan default', function (): void {
    $brandB = TestPlatform::brand(TestPlatform::BRAND_B);
    $plan = Plan::query()->firstOrCreate(['key' => 'test-plan'], ['name' => 'Test', 'default_features' => ['wishlist' => true, 'reviews' => true]]);

    $brandB->forceFill(['plan_id' => $plan->id])->save();
    TenantFeatureFlag::query()->create(['tenant_id' => $brandB->id, 'key' => 'reviews', 'enabled' => false]);
    app(FeatureFlags::class)->forget($brandB->id);

    $flags = $brandB->run(fn (): array => app(FeatureFlags::class)->all());

    expect($flags['wishlist'])->toBeTrue()
        ->and($flags['reviews'])->toBeFalse()
        ->and($flags['blog'])->toBeFalse();
});

it('keeps flags per brand', function (): void {
    $brandB = TestPlatform::brand(TestPlatform::BRAND_B);
    TenantFeatureFlag::query()->create(['tenant_id' => $brandB->id, 'key' => 'wishlist', 'enabled' => true]);
    app(FeatureFlags::class)->forget($brandB->id);

    $this->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1/storefront/config')
        ->assertJsonPath('data.features.wishlist', true);
    $this->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_C).'/api/v1/storefront/config')
        ->assertJsonPath('data.features.wishlist', false);
});

it('answers 403 feature_disabled on a gated route', function (): void {
    Route::middleware(['tenant:api', 'api', 'feature:wishlist'])
        ->get('api/v1/test-wishlist', fn () => ['ok' => true]);

    $this->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_C).'/api/v1/test-wishlist')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'feature_disabled');

    $brandB = TestPlatform::brand(TestPlatform::BRAND_B);
    TenantFeatureFlag::query()->create(['tenant_id' => $brandB->id, 'key' => 'wishlist', 'enabled' => true]);
    app(FeatureFlags::class)->forget($brandB->id);

    $this->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1/test-wishlist')->assertOk();
});
