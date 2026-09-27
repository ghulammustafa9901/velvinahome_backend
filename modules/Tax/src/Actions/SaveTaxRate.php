<?php

declare(strict_types=1);

namespace Modules\Tax\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Validation\ValidationException;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;
use Modules\Tax\Models\TaxRate;

/**
 * A tax rate in basis points (1700 = 17%). Global (no country), per country, or per province;
 * the most specific active rate wins.
 */
final class SaveTaxRate implements Action
{
    use ChecksPermission;

    public function handle(?TaxRate $rate, string $name, int $rateBps, ?string $countryCode, ?string $province, bool $isActive = true, ?Authorizable $actor = null): TaxRate
    {
        $this->authorizePermission($actor, 'settings.manage');

        if ($rateBps < 0 || $rateBps > 10_000) {
            throw ValidationException::withMessages(['rate_bps' => __('A rate is between 0% and 100%.')]);
        }

        if ($countryCode === null && $province !== null) {
            throw ValidationException::withMessages(['province' => __('A province rate needs its country.')]);
        }

        $rate ??= new TaxRate;
        $rate->fill([
            'name' => trim($name),
            'rate_bps' => $rateBps,
            'country_code' => $countryCode === null ? null : strtoupper($countryCode),
            'province' => $province === null || trim($province) === '' ? null : trim($province),
            'is_active' => $isActive,
        ])->save();

        return $rate;
    }
}
