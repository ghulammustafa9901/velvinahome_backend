<?php

declare(strict_types=1);

namespace Modules\Platform\Tenancy;

use Modules\Platform\Enums\DomainKind;
use Modules\Platform\Models\Domain;
use Modules\Platform\Models\Tenant;

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
 */
final class TenantResolver
{
    public function resolve(string $host, DomainKind $kind): ?Tenant
    {
        $domain = Domain::query()
            ->where('hostname', strtolower($host))
            ->where('kind', $kind->value)
            ->with('tenant.databaseCredentials')
            ->first();

        if ($domain === null) {
            return null;
        }

        $tenant = $domain->tenant;

        if (config('platform.topology') === 'dedicated' && $tenant->id !== config('platform.pinned_tenant')) {
            return null;
        }

        return $tenant;
    }
}
