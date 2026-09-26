<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

/**
 * Lets other modules run work per tenant (reindexing, maintenance commands) without touching
 * the landlord database themselves.
 */
interface TenantDirectory
{
    /**
     * @return list<string>
     */
    public function activeTenantIds(): array;

    /**
     * Run a callback inside the tenant's context and return its result. Tenancy is restored
     * afterwards even if the callback throws.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runIn(string $tenantId, callable $callback): mixed;
}
