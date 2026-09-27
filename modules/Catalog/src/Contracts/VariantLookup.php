<?php

declare(strict_types=1);

namespace Modules\Catalog\Contracts;

/**
 * Admin-side variant search (SKU, barcode, product title) for pickers in other modules'
 * screens, e.g. goods receipts.
 */
interface VariantLookup
{
    /**
     * @return array<string, string> variant ULID => "SKU — Product title"
     */
    public function search(string $term, int $limit = 25): array;
}
