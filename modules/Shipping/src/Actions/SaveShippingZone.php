<?php

declare(strict_types=1);

namespace Modules\Shipping\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Shipping\Models\ShippingZone;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Where a set of shipping methods applies. An empty list means "any"; the most specific
 * matching zone (cities > provinces > countries) wins for an address.
 */
final class SaveShippingZone implements Action
{
    use ChecksPermission;

    /**
     * @param  list<string>  $countries
     * @param  list<string>  $provinces
     * @param  list<string>  $cities
     */
    public function handle(?ShippingZone $zone, string $name, array $countries, array $provinces = [], array $cities = [], bool $isActive = true, ?Authorizable $actor = null): ShippingZone
    {
        $this->authorizePermission($actor, 'settings.manage');

        $clean = fn (array $values, bool $upper = false): array => array_values(array_unique(array_filter(
            array_map(fn (string $value): string => $upper ? strtoupper(trim($value)) : trim($value), $values),
            fn (string $value): bool => $value !== '',
        )));

        $zone ??= new ShippingZone;
        $zone->fill([
            'name' => trim($name),
            'countries' => $clean($countries, true),
            'provinces' => $clean($provinces),
            'cities' => $clean($cities),
            'is_active' => $isActive,
        ])->save();

        return $zone;
    }
}
