<?php

declare(strict_types=1);

namespace Modules\Shipping\Seeders;

use Illuminate\Database\Seeder;
use Modules\Settings\Contracts\TenantSettings;
use Modules\Shipping\Models\ShippingZone;
use Modules\Support\ValueObjects\Money;

/**
 * A starting point every brand edits (spec §4.6 "default tax/shipping"): nationwide standard
 * delivery, COD allowed, flat Rs 250, free over Rs 5,000. Only seeded when a brand has no
 * shipping set up at all.
 */
final class DefaultShippingSeeder extends Seeder
{
    public function run(TenantSettings $settings): void
    {
        if (ShippingZone::query()->exists()) {
            return;
        }

        $currency = $settings->string('store.currency', 'PKR');
        $zone = ShippingZone::query()->create(['name' => 'Nationwide', 'countries' => [$settings->string('store.country', 'PK')]]);

        $method = $zone->methods()->create([
            'code' => 'standard',
            'name' => 'Standard delivery',
            'is_cod_allowed' => true,
            'min_days' => 2,
            'max_days' => 5,
        ]);

        $rate = $method->rates()->make(['strategy' => 'flat', 'free_over_amount' => Money::fromMajorUnits(5000, $currency)->amount]);
        $rate->amount = Money::fromMajorUnits(250, $currency);
        $rate->save();
    }
}
