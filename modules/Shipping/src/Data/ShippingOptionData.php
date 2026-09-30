<?php

declare(strict_types=1);

namespace Modules\Shipping\Data;

use Modules\Support\ValueObjects\Money;

final readonly class ShippingOptionData
{
    public function __construct(
        public string $code,
        public string $name,
        public Money $amount,
        public bool $codAllowed,
        public ?int $minDays,
        public ?int $maxDays,
    ) {}

    /**
     * @return array{code: string, name: string, price: Money, cod_allowed: bool, min_days: int|null, max_days: int|null}
     */
    public function toApi(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'price' => $this->amount,
            'cod_allowed' => $this->codAllowed,
            'min_days' => $this->minDays,
            'max_days' => $this->maxDays,
        ];
    }
}
