<?php

declare(strict_types=1);

namespace Modules\Imports\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Imports\Actions\RunImport;
use Modules\Imports\Actions\StartCsvImport;
use Modules\Imports\Models\ImportRun;
use Modules\Platform\Contracts\TenantDirectory;
use RuntimeException;

/**
 * The admin's CSV import from the command line, for operators onboarding a brand (spec §13
 * Phase 8) and for loading test catalogs. Same Actions, same checks, same report; the run is
 * executed here and waits for nothing (the queued job then finds it finished).
 */
final class ImportCsvCommand extends Command
{
    protected $signature = 'imports:csv {tenant : Tenant id} {file : Path to the CSV file}
        {--stock : The file holds stock counts (sku, quantity) instead of products}
        {--dry-run : Check every row, write nothing}';

    protected $description = 'Import products or stock counts from a CSV into one brand';

    public function handle(TenantDirectory $tenants, StartCsvImport $start, RunImport $runImport): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file) || ! is_readable($file)) {
            $this->components->error("Cannot read {$file}.");

            return self::FAILURE;
        }

        $source = $this->option('stock') ? ImportRun::SOURCE_CSV_STOCK : ImportRun::SOURCE_CSV_PRODUCTS;

        $run = $tenants->runIn((string) $this->argument('tenant'), function () use ($file, $source, $start, $runImport): ImportRun {
            $path = 'imports/'.Str::lower(Str::random(24)).'.csv';
            $stream = fopen($file, 'rb');

            if ($stream === false) {
                throw new RuntimeException("Cannot open {$file}.");
            }

            try {
                Storage::disk(StartCsvImport::DISK)->writeStream($path, $stream);
            } finally {
                fclose($stream);
            }

            $run = $start->handle($source, $path, (bool) $this->option('dry-run'));

            return $runImport->handle($run->ulid)->refresh();
        });

        foreach ($run->counts as $entity => $counts) {
            $this->components->twoColumnDetail($entity, collect($counts)->map(fn (int $n, string $what): string => "{$what} {$n}")->implode(', '));
        }

        foreach (array_slice($run->issues, 0, 20) as $issue) {
            $this->line(sprintf('  <fg=%s>%s</> %s %s: %s', $issue['level'] === 'error' ? 'red' : 'yellow', $issue['level'], $issue['entity'], $issue['ref'] ?? '', $issue['message']));
        }

        if ($run->status !== 'completed') {
            $this->components->error('Import failed: '.$run->error);

            return self::FAILURE;
        }

        $this->components->info("Import {$run->ulid} completed with {$run->issue_count} issue(s).");

        return self::SUCCESS;
    }
}
