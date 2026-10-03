<?php

declare(strict_types=1);

namespace Modules\Imports\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Imports\Jobs\RunImportJob;
use Modules\Imports\Models\ImportRun;
use Modules\Imports\Support\RemoteUrl;
use Modules\Imports\Support\WooCommerceImport;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Queue a WooCommerce import. The API keys are stored encrypted on the run and erased when it
 * ends; only one import runs at a time.
 */
final class StartWooCommerceImport implements Action
{
    use ChecksPermission;

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @param  list<string>  $entities  some of WooCommerceImport::ENTITIES
     */
    public function handle(string $storeUrl, string $consumerKey, string $consumerSecret, bool $dryRun, array $entities, ?Authorizable $actor = null): ImportRun
    {
        $this->authorizePermission($actor, 'settings.manage');

        $storeUrl = RemoteUrl::check($storeUrl, 'store_url');
        $entities = array_values(array_intersect(WooCommerceImport::ENTITIES, $entities));

        if (preg_match('/^ck_[A-Za-z0-9]{20,64}$/', trim($consumerKey)) !== 1) {
            throw ValidationException::withMessages(['consumer_key' => __('A WooCommerce consumer key starts with ck_.')]);
        }

        if (preg_match('/^cs_[A-Za-z0-9]{20,64}$/', trim($consumerSecret)) !== 1) {
            throw ValidationException::withMessages(['consumer_secret' => __('A WooCommerce consumer secret starts with cs_.')]);
        }

        if ($entities === []) {
            throw ValidationException::withMessages(['entities' => __('Choose at least one thing to import.')]);
        }

        return $this->db->connection()->transaction(function () use ($storeUrl, $consumerKey, $consumerSecret, $dryRun, $entities, $actor): ImportRun {
            // Serialise starts in the database, so two clicks cannot start two runs.
            $this->db->connection()->statement("select pg_advisory_xact_lock(hashtext('import_runs'))");

            if (ImportRun::query()->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['store_url' => __('Another import is still running. Wait for it to finish.')]);
            }

            $run = ImportRun::query()->create([
                'source' => ImportRun::SOURCE_WOOCOMMERCE,
                'status' => 'queued',
                'dry_run' => $dryRun,
                'options' => ['store_url' => $storeUrl, 'entities' => $entities],
                'credentials' => ['key' => trim($consumerKey), 'secret' => trim($consumerSecret)],
                'counts' => [],
                'issues' => [],
                'actor_ulid' => $actor?->getAttribute('ulid'),
            ]);

            RunImportJob::dispatch($run->ulid)->afterCommit();

            return $run;
        });
    }
}
