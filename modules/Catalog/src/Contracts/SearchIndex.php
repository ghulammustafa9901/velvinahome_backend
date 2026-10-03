<?php

declare(strict_types=1);

namespace Modules\Catalog\Contracts;

/**
 * Lifecycle of the store's search collection, for installation, deletion and the
 * `search:reindex` command.
 */
interface SearchIndex
{
    /**
     * Create the store's collections if they do not exist. Idempotent.
     */
    public function ensureCollections(): void;

    /**
     * Rebuild the store's product collection from PostgreSQL, the source of truth.
     *
     * @return int documents indexed
     */
    public function reindex(): int;

    public function dropCollections(): void;
}
