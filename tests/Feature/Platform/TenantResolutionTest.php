<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Actions\ChangeTenantStatus;
use Modules\Platform\Enums\DomainKind;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\Domain;
use Modules\Platform\Tenancy\TenantDatabaseConfig;
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

    // Resolved (and cached) while active: the suspension must still apply on the next request.
    $this->getJson($url)->assertOk();
    app(ChangeTenantStatus::class)->handle($tenant, TenantStatus::Suspended, 'test');

    try {
        $this->getJson($url)->assertStatus(503);
    } finally {
        app(ChangeTenantStatus::class)->handle($tenant->refresh(), TenantStatus::Active, 'test');
    }

    $this->getJson($url)->assertOk();
});

it('serves a resolved host without touching the landlord database', function (): void {
    $url = 'http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1/storefront/config';
    $this->getJson($url)->assertOk();

    $landlord = 0;
    DB::listen(function (QueryExecuted $query) use (&$landlord): void {
        $landlord += $query->connectionName === 'landlord' ? 1 : 0;
    });

    $this->getJson($url)->assertOk()->assertJsonPath('data.store.name', 'Brand FIX-B');

    expect($landlord)->toBe(0);
});

it('caches only plain data for a resolution, never objects', function (): void {
    $this->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1/storefront/config')->assertOk();

    $cached = Cache::store()->get('tenancy:host:v1:api:'.TestPlatform::apiHost(TestPlatform::BRAND_B));

    $password = TestPlatform::brand(TestPlatform::BRAND_B)->databaseCredentials?->app_password;

    // Plain arrays only, and the database password as the landlord holds it: ciphertext.
    expect($cached)->toBeArray()
        ->and(json_encode($cached))->not->toBeFalse()
        ->and($cached['database']['app_password'])->not->toBe($password)
        ->and(Crypt::decryptString($cached['database']['app_password']))->toBe($password);
});

it('stops resolving a removed domain at once', function (): void {
    $brand = TestPlatform::brand(TestPlatform::BRAND_C);
    $host = 'api-extra-'.Str::lower(Str::random(6)).'.test';
    $domain = Domain::query()->create(['tenant_id' => $brand->id, 'hostname' => $host, 'kind' => DomainKind::Api, 'is_primary' => false]);

    $this->getJson("http://{$host}/api/v1/storefront/config")->assertOk()->assertJsonPath('data.store.name', 'Brand FIX-C');

    $domain->delete();

    $this->getJson("http://{$host}/api/v1/storefront/config")->assertNotFound();
});

it('sends tenant application traffic through the pooler and migrations around it', function (): void {
    $brand = TestPlatform::brand(TestPlatform::BRAND_B);
    $credentials = $brand->databaseCredentials;
    config(['database.pooler' => ['host' => 'pgbouncer.internal', 'port' => '6432']]);

    $config = new TenantDatabaseConfig($brand, $credentials);

    expect($config->connection())->toMatchArray(['host' => 'pgbouncer.internal', 'port' => '6432', 'database' => $credentials->database, 'username' => $credentials->app_username])
        ->and($config->migratorConnection())->toMatchArray(['host' => $credentials->host, 'port' => $credentials->port, 'username' => $credentials->migration_username]);

    config(['database.pooler' => []]);
    expect((new TenantDatabaseConfig($brand, $credentials))->connection()['host'])->toBe($credentials->host);
});

it('ends tenancy after each request so the next request starts clean', function (): void {
    $this->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1/storefront/config')->assertOk();

    expect(tenancy()->initialized)->toBeFalse()
        ->and(config('database.default'))->toBe('landlord');
});
