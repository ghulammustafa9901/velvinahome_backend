<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

/**
 * The current tenant's effective feature flags: plan defaults overridden by the tenant's own
 * rows (data-model.md §1.5). Resolved once per request and cached per tenant.
 *
 * Published by Platform because flags live in the landlord database; every other module
 * asks through this contract and never touches the landlord connection itself.
 */
interface FeatureFlags
{
    public function enabled(string $feature): bool;

    /**
     * @return array<string, bool>
     */
    public function all(): array;

    /**
     * Drop the cached flag set for a tenant after its plan or overrides change.
     */
    public function forget(string $tenantId): void;
}
