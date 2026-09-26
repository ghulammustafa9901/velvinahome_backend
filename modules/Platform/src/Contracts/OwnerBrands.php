<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

/**
 * The other brands a signed-in company owner may switch to from the current brand's admin
 * (spec §4.1a). Names and ids only — nothing from the other brands' databases.
 */
interface OwnerBrands
{
    /**
     * @return list<array{id: string, name: string}>
     */
    public function otherBrandsFor(string $staffUlid): array;
}
