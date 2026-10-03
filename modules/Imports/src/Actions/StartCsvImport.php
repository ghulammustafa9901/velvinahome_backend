<?php

declare(strict_types=1);

namespace Modules\Imports\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Imports\Jobs\RunImportJob;
use Modules\Imports\Models\ImportRun;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Queue a CSV import of a file already stored on the private disk (the admin upload
 * puts it there). The file is deleted when the run ends.
 */
final class StartCsvImport implements Action
{
    use ChecksPermission;

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Shared by web and queue servers (object storage in production). */
    public const DISK = 'private';

    public function __construct(private readonly DatabaseManager $db) {}

    public function handle(string $source, string $storedPath, bool $dryRun, ?Authorizable $actor = null): ImportRun
    {
        $this->authorizePermission($actor, $source === ImportRun::SOURCE_CSV_STOCK ? 'inventory.adjust' : 'products.manage');

        if (! in_array($source, [ImportRun::SOURCE_CSV_PRODUCTS, ImportRun::SOURCE_CSV_STOCK], true)) {
            throw ValidationException::withMessages(['source' => __('Unknown import type.')]);
        }

        $disk = Storage::disk(self::DISK);

        if (! str_starts_with($storedPath, 'imports/') || str_contains($storedPath, '..') || ! $disk->exists($storedPath) || $disk->size($storedPath) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => __('Upload a CSV file of at most 5 MB.')]);
        }

        return $this->db->connection()->transaction(function () use ($source, $storedPath, $dryRun, $actor): ImportRun {
            $this->db->connection()->statement("select pg_advisory_xact_lock(hashtext('import_runs'))");

            if (ImportRun::query()->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['file' => __('Another import is still running. Wait for it to finish.')]);
            }

            $run = ImportRun::query()->create([
                'source' => $source,
                'status' => 'queued',
                'dry_run' => $dryRun,
                'options' => [],
                'file_path' => $storedPath,
                'counts' => [],
                'issues' => [],
                'actor_ulid' => $actor?->getAttribute('ulid'),
            ]);

            RunImportJob::dispatch($run->ulid)->afterCommit();

            return $run;
        });
    }
}
