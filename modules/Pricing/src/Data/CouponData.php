<?php

declare(strict_types=1);

namespace Modules\Pricing\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

final class CouponData extends Data
{
    /**
     * @param  list<string>  $productUlids
     * @param  list<string>  $categoryUlids
     */
    public function __construct(
        #[Regex('/^[A-Za-z0-9_-]{3,50}$/')]
        public readonly string $code,
        #[In(['percentage', 'fixed', 'free_shipping'])]
        public readonly string $discountType,
        // Basis points (percentage) or minor units (fixed).
        #[Min(0)]
        public readonly int $value = 0,
        #[Min(0)]
        public readonly ?int $minimumSpendAmount = null,
        #[Min(1)]
        public readonly ?int $usageLimit = null,
        #[Min(1)]
        public readonly ?int $usageLimitPerCustomer = null,
        public readonly ?CarbonImmutable $startsAt = null,
        public readonly ?CarbonImmutable $endsAt = null,
        public readonly array $productUlids = [],
        public readonly array $categoryUlids = [],
        public readonly bool $isActive = true,
    ) {}
}
