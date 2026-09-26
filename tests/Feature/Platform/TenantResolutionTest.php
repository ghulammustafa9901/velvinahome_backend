<?php

declare(strict_types=1);

use Modules\Platform\Actions\ChangeTenantStatus;
use Modules\Platform\Enums\TenantStatus;
use Tests\Support\TestPlatform;

/*
| Spec §4.2: the tenant comes from the hostname only; an unknown host is a 404 and never a
| fallback tenant; a host serves only its own kind of surface.
*/

it('resolves a known API host to its own tenant', function (): void {
    $this->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1/storefront/config')
        ->assertOk()
        ->assertJsonPath('data.store.name', 'Brand FIX-B');

    $this->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_C).'/api/v1/storefront/config')
        ->assertOk()
        ->assertJsonPath('data.store.name', 'Brand FIX-C');
});

it('returns 404 for an unknown host instead of falling back to a tenant', function (string $host): void {
    $this->getJson("http://{$host}/api/v1/storefront/config")->assertNotFound();
})->with([
    'unknown domain' => 'api.nobody.test',
    'platform host' => 'platform.test',
    'bare localhost' => 'localhost',
    'ip address' => '127.0.0.1',
]);

it('never serves the API on a storefront or admin host', function (string $kind): void {
    $host = $kind === 'admin'
        ? TestPlatform::adminHost(TestPlatform::BRAND_B)
        : TestPlatform::BRAND_B.'.test';

    $this->getJson("http://{$host}/api/v1/storefront/config")->assertNotFound();
})->with(['admin', 'storefront']);

it('ignores tenant hints in headers, query and body', function (): void {
    $brandC = TestPlatform::brand(TestPlatform::BRAND_C);

    $this->withHeaders(['X-Tenant' => $brandC->id, 'X-Tenant-Id' => $brandC->id])
        ->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1/storefront/config?tenant='.$brandC->id)
        ->assertOk()
        ->assertJsonPath('data.store.name', 'Brand FIX-B');
});

it('answers 503 for a suspended tenant and serves it again once re-activated', function (): void {
    $tenant = TestPlatform::brand(TestPlatform::BRAND_A);
    $url = 'http://'.TestPlatform::apiHost(TestPlatform::BRAND_A).'/api/v1/storefront/config';

    app(ChangeTenantStatus::class)->handle($tenant, TenantStatus::Suspended, 'test');

    try {
        $this->getJson($url)->assertStatus(503);
    } finally {
        app(ChangeTenantStatus::class)->handle($tenant->refresh(), TenantStatus::Active, 'test');
    }

    $this->getJson($url)->assertOk();
});

it('ends tenancy after each request so the next request starts clean', function (): void {
    $this->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1/storefront/config')->assertOk();

    expect(tenancy()->initialized)->toBeFalse()
        ->and(config('database.default'))->toBe('landlord');
});
