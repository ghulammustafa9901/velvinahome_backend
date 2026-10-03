<?php

declare(strict_types=1);

namespace Modules\Platform\Tenancy;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Modules\Platform\Enums\DomainKind;
use Modules\Platform\Models\Domain;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantDatabase;

/**
 * Hostname → tenant, and nothing else (spec §4.2).
 *
 * - The hostname must be a registered domain of exactly the requested kind. An admin host
 *   never resolves for an API route and vice versa.
 * - An unknown host resolves to nothing. There is no default tenant, in any topology.
 * - Nothing from the request body, query or headers participates.
 *
 * In the dedicated topology the installation is pinned to one tenant; the host must still
 * be one of that tenant's domains of the right kind, so moving a tenant between topologies
 * changes configuration only, never which requests are accepted.
 *
 * Resolutions are cached in the central (untagged) cache, so a storefront request does not
 * open a landlord connection (docs/performance/load-test.md). Only raw attribute arrays are
 * cached — no objects, the cache unserializes none — and the database passwords stay the
 * APP_KEY ciphertext they are in the landlord. Every write to a tenant, its domains or its
 * credentials forgets the entries (PlatformServiceProvider); the TTL bounds anything else.
 */
final class TenantResolver
{
    public const CACHE_SECONDS = 300;

    private const KEY = 'tenancy:host:v1:';

    public function __construct(private readonly CacheFactory $cache) {}

    public function resolve(string $host, DomainKind $kind): ?Tenant
    {
        $host = strtolower($host);
        $key = self::KEY.$kind->value.':'.$host;
        $cached = $this->store()->get($key);

        if (is_array($cached) && is_array($cached['tenant'] ?? null)) {
            $database = $cached['database'] ?? null;
            $tenant = $this->hydrate($cached['tenant'], is_array($database) ? $database : null);
        } else {
            $tenant = Domain::query()
                ->where('hostname', $host)
                ->where('kind', $kind->value)
                ->with('tenant.databaseCredentials')
                ->first()?->tenant;

            if ($tenant === null) {
                return null;
            }

            $this->store()->put($key, [
                'tenant' => $tenant->getAttributes(),
                'database' => $tenant->databaseCredentials?->getAttributes(),
            ], self::CACHE_SECONDS);
        }

        if (config('platform.topology') === 'dedicated' && $tenant->id !== config('platform.pinned_tenant')) {
            return null;
        }

        return $tenant;
    }

    public function forget(string $host, DomainKind $kind): void
    {
        $this->store()->forget(self::KEY.$kind->value.':'.strtolower($host));
    }

    public function forgetTenant(string $tenantId): void
    {
        foreach (Domain::query()->where('tenant_id', $tenantId)->get() as $domain) {
            $this->forget($domain->hostname, $domain->kind);
        }
    }

    public function forgetAll(): void
    {
        foreach (Domain::query()->get() as $domain) {
            $this->forget($domain->hostname, $domain->kind);
        }
    }

    /**
     * @param  array<mixed>  $attributes
     * @param  array<mixed>|null  $database
     */
    private function hydrate(array $attributes, ?array $database): Tenant
    {
        $tenant = (new Tenant)->newFromBuilder($attributes);
        $tenant->setRelation('databaseCredentials', $database === null ? null : (new TenantDatabase)->newFromBuilder($database));

        return $tenant;
    }

    /**
     * Central, never the tenant-tagged repository: resolution happens before tenancy starts,
     * and invalidation may run inside a tenant context.
     */
    private function store(): Repository
    {
        return $this->cache->store();
    }
}
