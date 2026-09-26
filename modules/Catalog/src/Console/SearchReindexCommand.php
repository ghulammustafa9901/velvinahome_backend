<?php

declare(strict_types=1);

namespace Modules\Catalog\Console;

use Illuminate\Console\Command;
use Modules\Catalog\Actions\ReindexSearch;

final class SearchReindexCommand extends Command
{
    protected $signature = 'search:reindex {--tenant=* : Tenant ids (default: every active tenant)}';

    protected $description = 'Rebuild tenants\' Typesense collections from PostgreSQL';

    public function handle(ReindexSearch $reindex): int
    {
        /** @var list<string> $only */
        $only = $this->option('tenant');

        foreach ($reindex->handle($only === [] ? null : $only) as $tenant => $count) {
            $this->components->twoColumnDetail($tenant, is_int($count) ? "{$count} products" : "<fg=red>{$count}</>");
        }

        return self::SUCCESS;
    }
}
