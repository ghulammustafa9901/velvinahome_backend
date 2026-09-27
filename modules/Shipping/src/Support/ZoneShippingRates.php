<?php

declare(strict_types=1);

namespace Modules\Shipping\Support;

use Modules\Customers\Data\AddressData;
use Modules\Shipping\Contracts\ShippingRates;
use Modules\Shipping\Data\ShippingOptionData;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingRate;
use Modules\Shipping\Models\ShippingZone;
use Modules\Support\ValueObjects\Money;

final class ZoneShippingRates implements ShippingRates
{
    public function options(AddressData $address, Money $subtotal, int $weightGrams): array
    {
        $zone = ShippingZone::query()->where('is_active', true)->with('methods.rates')->get()
            ->map(fn (ShippingZone $zone): array => [$zone, $zone->specificityFor($address->countryCode, $address->province, $address->city)])
            ->filter(fn (array $pair): bool => $pair[1] > 0)
            ->sortByDesc(fn (array $pair): int => $pair[1])
            ->first()[0] ?? null;

        if (! $zone instanceof ShippingZone) {
            return [];
        }

        $options = [];

        foreach ($zone->methods->where('is_active', true) as $method) {
            /** @var ShippingMethod $method */
            $rate = $method->rates->first(fn (ShippingRate $rate): bool => $rate->applies($weightGrams, $subtotal) && $rate->amount->currency === $subtotal->currency);

            if (! $rate instanceof ShippingRate) {
                continue;
            }

            $free = $rate->free_over_amount !== null && $subtotal->amount >= $rate->free_over_amount;

            $options[] = new ShippingOptionData(
                code: $method->code,
                name: $method->name,
                amount: $free ? Money::zero($subtotal->currency) : $rate->amount,
                codAllowed: $method->is_cod_allowed,
                minDays: $method->min_days,
                maxDays: $method->max_days,
            );
        }

        return $options;
    }
}
