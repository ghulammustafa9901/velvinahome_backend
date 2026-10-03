<?php

declare(strict_types=1);

namespace Modules\Catalog\Console;

use Illuminate\Console\Command;
use Modules\Catalog\Actions\ReindexSearch;

final class SearchReindexCommand extends Command
{
    protected $signature = 'search:reindex';

    protected $description = 'Rebuild the Typesense product collection and synonyms from PostgreSQL';

    public function handle(ReindexSearch $reindex): int
    {
        $this->components->info($reindex->handle().' products indexed.');

        return self::SUCCESS;
    }
}
