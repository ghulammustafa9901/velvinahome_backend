<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalog\Contracts\VariantCatalog;
use Modules\Catalog\Data\VariantReference;
use Modules\Inventory\Exceptions\InventoryRuleViolation;
use Modules\Inventory\Models\Location;

/**
 * Turns public ids from DTOs into the internal references the ledger works with.
 */
final class Resolver
{
    public function __construct(private readonly VariantCatalog $catalog) {}

    public function variant(string $ulid): VariantReference
    {
        return $this->catalog->findByUlid($ulid) ?? throw InventoryRuleViolation::unknownVariant($ulid);
    }

    public function variantById(int $id): VariantReference
    {
        return $this->catalog->findById($id) ?? throw InventoryRuleViolation::unknownVariant((string) $id);
    }

    public function location(string $ulid): Location
    {
        return Location::query()->where('ulid', $ulid)->where('is_active', true)->first()
            ?? throw InventoryRuleViolation::unknownLocation($ulid);
    }

    public function actorUlid(?Authorizable $actor): ?string
    {
        if (! $actor instanceof Model) {
            return null;
        }

        $ulid = $actor->getAttribute('ulid');

        return is_string($ulid) ? $ulid : null;
    }
}
