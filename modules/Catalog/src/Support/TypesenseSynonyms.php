<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\Catalog\Models\SearchSynonym;

/**
 * Pushes the store's synonyms to its Typesense synonym set.
 *
 * Typesense 30 replaced per-collection synonyms with named synonym sets that collections
 * reference; the PHP client does not cover them yet, so this talks to the REST API directly.
 * Every sync writes the complete set from PostgreSQL, so it is idempotent and self-healing.
 */
final class TypesenseSynonyms
{
    public static function setName(): string
    {
        return config('scout.prefix').'synonyms';
    }

    public function sync(): void
    {
        $items = SearchSynonym::query()->orderBy('id')->get()->map(fn (SearchSynonym $synonym): array => array_filter([
            'id' => $synonym->ulid,
            'root' => $synonym->root,
            'synonyms' => array_values($synonym->terms),
        ], fn ($value): bool => $value !== null))->all();

        $this->client()->put('/synonym_sets/'.self::setName(), ['items' => $items])->throw();
    }

    public function delete(): void
    {
        $response = $this->client()->delete('/synonym_sets/'.self::setName());

        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    private function client(): PendingRequest
    {
        $node = config('scout.typesense.client-settings.nodes.0');

        return Http::baseUrl(sprintf('%s://%s:%s%s', $node['protocol'], $node['host'], $node['port'], $node['path'] ?? ''))
            ->withHeaders(['X-TYPESENSE-API-KEY' => (string) config('scout.typesense.client-settings.api_key')])
            ->acceptJson()
            ->timeout(5);
    }
}
