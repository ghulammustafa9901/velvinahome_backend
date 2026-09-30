<?php

declare(strict_types=1);

namespace Modules\Imports\Actions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Imports\Models\ImportRun;
use Modules\Imports\Support\CsvImport;
use Modules\Imports\Support\ImportLog;
use Modules\Imports\Support\WooCommerceClient;
use Modules\Imports\Support\WooCommerceImport;
use Modules\Support\Contracts\Action;
use RuntimeException;
use Throwable;

/**
 * Execute a queued run. Whatever happens, the run ends completed or failed, with its report,
 * and its source credentials erased.
 */
final class RunImport implements Action
{
    public function __construct(private readonly Container $container) {}

    public function handle(string $runUlid): ImportRun
    {
        $run = ImportRun::query()->where('ulid', $runUlid)->firstOrFail();

        if ($run->status !== 'queued') {
            return $run;
        }

        $run->forceFill(['status' => 'running', 'started_at' => Carbon::now()])->save();
        $log = new ImportLog($run);

        try {
            match ($run->source) {
                ImportRun::SOURCE_WOOCOMMERCE => $this->container->make(WooCommerceImport::class)->run(
                    $run,
                    new WooCommerceClient((string) $run->options['store_url'], (string) ($run->credentials['key'] ?? ''), (string) ($run->credentials['secret'] ?? '')),
                    $log,
                ),
                ImportRun::SOURCE_CSV_PRODUCTS, ImportRun::SOURCE_CSV_STOCK => $this->container->make(CsvImport::class)->run($run, $log),
                default => throw new RuntimeException("Unknown import source {$run->source}."),
            };

            $run->status = 'completed';
        } catch (Throwable $e) {
            Log::warning('Import run failed', ['run' => $run->ulid, 'exception' => $e::class]);
            $run->status = 'failed';
            $run->error = mb_substr($e->getMessage(), 0, 2000);
        }

        $run->forceFill(['credentials' => null, 'step' => null, 'finished_at' => Carbon::now()]);
        $log->flush();

        return $run;
    }
}
