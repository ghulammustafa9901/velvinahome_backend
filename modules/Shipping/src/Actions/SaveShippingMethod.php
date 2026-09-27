<?php

declare(strict_types=1);

namespace Modules\Shipping\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingRate;
use Modules\Shipping\Models\ShippingZone;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;
use Modules\Support\ValueObjects\Money;

/**
 * A delivery method in a zone with its flat rate, optionally free over an order value.
 * (Weight and order-value bands are supported by the rate table; the admin edits flat rates
 * in v1.)
 */
final class SaveShippingMethod implements Action
{
    use ChecksPermission;

    public function __construct(private readonly DatabaseManager $db) {}

    public function handle(
        ShippingZone $zone,
        ?ShippingMethod $method,
        string $code,
        string $name,
        Money $flatRate,
        ?Money $freeOver = null,
        bool $codAllowed = true,
        bool $isActive = true,
        ?int $minDays = null,
        ?int $maxDays = null,
        ?Authorizable $actor = null,
    ): ShippingMethod {
        $this->authorizePermission($actor, 'settings.manage');

        if (preg_match('/^[a-z0-9_]{2,50}$/', $code) !== 1) {
            throw ValidationException::withMessages(['code' => __('Use 2–50 lowercase letters, digits or underscores.')]);
        }

        if ($flatRate->isNegative() || ($freeOver !== null && $freeOver->isNegative())) {
            throw ValidationException::withMessages(['rate' => __('Amounts cannot be negative.')]);
        }

        if ($minDays !== null && $maxDays !== null && $minDays > $maxDays) {
            throw ValidationException::withMessages(['max_days' => __('The latest day must not be before the earliest.')]);
        }

        return $this->db->connection()->transaction(function () use ($zone, $method, $code, $name, $flatRate, $freeOver, $codAllowed, $isActive, $minDays, $maxDays): ShippingMethod {
            $method ??= new ShippingMethod(['shipping_zone_id' => $zone->id]);
            $method->fill([
                'code' => $code,
                'name' => trim($name),
                'is_cod_allowed' => $codAllowed,
                'is_active' => $isActive,
                'min_days' => $minDays,
                'max_days' => $maxDays,
            ])->save();

            $rate = ShippingRate::query()->where('shipping_method_id', $method->id)->orderBy('position')->first()
                ?? new ShippingRate(['shipping_method_id' => $method->id]);
            $rate->fill(['strategy' => 'flat', 'min_value' => null, 'max_value' => null, 'free_over_amount' => $freeOver?->amount]);
            $rate->amount = $flatRate;
            $rate->save();

            return $method;
        });
    }
}
