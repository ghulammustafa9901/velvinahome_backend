<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Modules\Catalog\Contracts\SearchIndex;
use Modules\Support\Contracts\Action;

/**
 * `search:reindex`: rebuilds the product collection (and its synonym set) from PostgreSQL,
 * the source of truth.
 */
final class ReindexSearch implements Action
{
    public function __construct(private readonly SearchIndex $index) {}

    /**
     * @return int documents indexed
     */
    public function handle(): int
    {
        return $this->index->reindex();
    }
}
