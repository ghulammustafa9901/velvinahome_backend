<?php

declare(strict_types=1);

namespace Modules\Catalog\Contracts;

/**
 * Lifecycle of the current tenant's search collections, for provisioning, deletion and the
 * `search:reindex` command. Always called inside tenant context.
 */
interface SearchIndex
{
    /**
     * Create the tenant's collections if they do not exist. Idempotent.
     */
    public function ensureCollections(): void;

    /**
     * Rebuild the tenant's product collection from PostgreSQL, the source of truth.
     *
     * @return int documents indexed
     */
    public function reindex(): int;

    public function dropCollections(): void;
}
