<?php

declare(strict_types=1);

namespace Modules\Tax\Contracts;

use Modules\Support\ValueObjects\Money;
use Modules\Tax\Data\TaxLineData;

interface TaxCalculator
{
    /**
     * Tax on a (post-discount) amount delivered to the given place, in the store's tax mode
     * (inclusive or exclusive, a tenant setting).
     */
    public function tax(Money $amount, ?string $countryCode, ?string $province): TaxLineData;
}
