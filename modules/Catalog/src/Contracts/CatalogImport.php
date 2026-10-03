<?php

declare(strict_types=1);

namespace Modules\Catalog\Contracts;

use Modules\Catalog\Data\ProductData;

/**
 * How a data migration writes into the catalog: through the same Actions staff use, so
 * imported data obeys every catalog rule. Entities are identified by public ULIDs only.
 */
interface CatalogImport
{
    /**
     * Run a bulk import: products saved or restocked inside are indexed for search together at
     * the end, instead of one search job per save and per stock change.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function inBulk(callable $work): mixed;

    /**
     * Create a category, or update it when a ULID is given and still exists.
     *
     * @return string category ULID
     */
    public function saveCategory(?string $ulid, string $name, ?string $parentUlid, ?string $slug, ?string $description): string;

    /**
     * @return string brand ULID
     */
    public function saveBrand(?string $ulid, string $name): string;

    /**
     * Create a product, or update an existing one: details, category links, and price and
     * compare-at price of variants matched by SKU. Variants that do not exist yet are not
     * added to an existing product; they come back in `skipped_skus`.
     *
     * @return array{product: string, variants: array<string, string>, skipped_skus: list<string>} variant ULIDs by SKU
     */
    public function saveProduct(?string $productUlid, ProductData $data, bool $active): array;

    /**
     * Whether the product already has images (a re-run does not attach them again).
     */
    public function hasImages(string $productUlid): bool;

    /**
     * Attach an image file (validated like an admin upload).
     */
    public function attachImage(string $productUlid, string $path, ?string $alt): void;

    /**
     * Storefront path of a product or category, e.g. "/products/linen-sheet".
     */
    public function path(string $type, string $ulid): ?string;

    /**
     * @param  list<string>  $skus
     * @return array<string, string> variant ULID by SKU, for the SKUs that exist
     */
    public function variantsBySku(array $skus): array;

    /**
     * @return string|null category ULID
     */
    public function categoryBySlug(string $slug): ?string;
}
