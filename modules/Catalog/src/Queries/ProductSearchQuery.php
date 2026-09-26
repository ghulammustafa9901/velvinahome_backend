<?php

declare(strict_types=1);

namespace Modules\Catalog\Queries;

use Illuminate\Validation\ValidationException;
use Modules\Catalog\Models\Product;
use Typesense\Client;

/**
 * Storefront search against the tenant's own Typesense collection (ADR-0005). Typesense
 * decides relevance, facets and typo tolerance; the products returned are re-read from
 * PostgreSQL, so price and stock shown are never the index's possibly stale copy.
 */
final class ProductSearchQuery
{
    public const FILTERS = ['category', 'brand', 'in_stock', 'price'];

    /** @var array<string, string> */
    public const SORTS = [
        'relevance' => '_text_match:desc,sales_rank:desc',
        'price' => 'price_min:asc',
        '-price' => 'price_min:desc',
        'newest' => 'created_at:desc',
        'best_selling' => 'sales_rank:desc',
    ];

    public function __construct(
        private readonly Client $typesense,
        private readonly CatalogQuery $catalog,
        private readonly ProductPresenter $presenter,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function search(string $q, array $filters, string $sort, int $page, int $perPage): array
    {
        $unknown = array_diff(array_keys($filters), self::FILTERS);

        if ($unknown !== [] || ! isset(self::SORTS[$sort])) {
            throw ValidationException::withMessages(array_filter([
                'filter' => $unknown === [] ? null : ['Unsupported filter: '.implode(', ', $unknown).'.'],
                'sort' => isset(self::SORTS[$sort]) ? null : ['Unsupported sort. Allowed: '.implode(', ', array_keys(self::SORTS)).'.'],
            ]));
        }

        $model = new Product;
        $result = $this->typesense->collections[$model->searchableAs()]->documents->search(array_merge(
            $model->typesenseSearchParameters(),
            [
                'q' => $q === '' ? '*' : $q,
                'filter_by' => $this->filterBy($filters),
                'sort_by' => self::SORTS[$sort],
                'facet_by' => 'brand,category_ids,in_stock,price_min',
                'max_facet_values' => 30,
                'page' => $page,
                'per_page' => $perPage,
                'include_fields' => 'id',
            ],
        ));

        $ulids = array_map(fn (array $hit): string => (string) $hit['document']['id'], $result['hits'] ?? []);
        $products = $this->catalog->live()->whereIn('ulid', $ulids)->get()->keyBy('ulid');
        $ordered = array_values(array_filter(array_map(fn (string $ulid) => $products->get($ulid), $ulids)));

        return [
            'data' => $this->presenter->summaries($ordered),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int) ($result['found'] ?? 0),
                'facets' => $this->facets($result['facet_counts'] ?? []),
            ],
        ];
    }

    /**
     * @return list<array{id: string, title: string, slug: string}>
     */
    public function suggest(string $q, int $limit): array
    {
        $model = new Product;
        $result = $this->typesense->collections[$model->searchableAs()]->documents->search([
            'q' => $q,
            'query_by' => 'title',
            'prefix' => 'true',
            'per_page' => $limit,
            'include_fields' => 'id,title,slug',
        ]);

        return array_values(array_map(fn (array $hit): array => [
            'id' => (string) $hit['document']['id'],
            'title' => (string) $hit['document']['title'],
            'slug' => (string) ($hit['document']['slug'] ?? ''),
        ], $result['hits'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filterBy(array $filters): string
    {
        $clauses = [];

        if (isset($filters['category'])) {
            $clauses[] = 'category_ids:=['.$this->values((string) $filters['category']).']';
        }

        if (isset($filters['brand'])) {
            $clauses[] = 'brand:=['.$this->values((string) $filters['brand']).']';
        }

        if (isset($filters['in_stock'])) {
            $clauses[] = 'in_stock:='.(filter_var($filters['in_stock'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false');
        }

        if (isset($filters['price']) && is_array($filters['price'])) {
            foreach (['gte' => '>=', 'lte' => '<='] as $key => $operator) {
                if (isset($filters['price'][$key]) && is_numeric($filters['price'][$key])) {
                    $clauses[] = 'price_min:'.$operator.(int) $filters['price'][$key];
                }
            }
        }

        return implode(' && ', $clauses);
    }

    /**
     * Comma-separated input to a backtick-quoted Typesense value list; backticks are
     * stripped so input can never break out of the quoting.
     */
    private function values(string $input): string
    {
        return implode(',', array_map(
            fn (string $value): string => '`'.str_replace('`', '', trim($value)).'`',
            array_filter(explode(',', $input), fn (string $value): bool => trim($value) !== ''),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $facetCounts
     * @return array<string, mixed>
     */
    private function facets(array $facetCounts): array
    {
        $facets = [];

        foreach ($facetCounts as $facet) {
            $name = (string) $facet['field_name'];

            if ($name === 'price_min') {
                $facets['price'] = [
                    'min' => isset($facet['stats']['min']) ? (int) $facet['stats']['min'] : null,
                    'max' => isset($facet['stats']['max']) ? (int) $facet['stats']['max'] : null,
                ];

                continue;
            }

            $facets[$name === 'category_ids' ? 'category' : $name] = array_map(
                fn (array $count): array => ['value' => (string) $count['value'], 'count' => (int) $count['count']],
                $facet['counts'] ?? [],
            );
        }

        return $facets;
    }
}
