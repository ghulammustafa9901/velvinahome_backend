<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * spatie/laravel-permission keeps permissions in a process-wide singleton and one shared
 * cache key. Unscoped, a queue worker that served brand B would answer brand C's permission
 * checks from B's roles — whose ids mean something else entirely in C's database.
 *
 * Re-keying the cache per tenant and discarding the in-memory copy on every switch keeps
 * authorization strictly per brand (spec §4.4).
 */
final class PermissionCacheBootstrapper implements TenancyBootstrapper
{
    private ?string $originalKey = null;

    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalKey ??= (string) config('permission.cache.key');

        config(['permission.cache.key' => $this->originalKey.'.tenant.'.$tenant->getTenantKey()]);
        $this->registrar->initializeCache();
    }

    public function revert(): void
    {
        config(['permission.cache.key' => $this->originalKey ?? 'spatie.permission.cache']);
        $this->registrar->initializeCache();
    }
}
