<?php

declare(strict_types=1);

namespace Modules\Imports\Support;

use Modules\Imports\Models\ImportMapping;

/**
 * External id → local reference for one import source, so every run after the first updates
 * what the earlier runs created.
 */
final class Mappings
{
    public function __construct(private readonly string $source) {}

    public function get(string $entity, string|int $externalId): ?string
    {
        return ImportMapping::query()
            ->where('source', $this->source)->where('entity', $entity)->where('external_id', (string) $externalId)
            ->value('local_ref');
    }

    public function put(string $entity, string|int $externalId, string $localRef): void
    {
        ImportMapping::query()->updateOrCreate(
            ['source' => $this->source, 'entity' => $entity, 'external_id' => (string) $externalId],
            ['local_ref' => $localRef],
        );
    }
}
