<?php

declare(strict_types=1);

namespace Modules\Settings\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Modules\Settings\Models\Setting;
use Modules\Settings\Support\DatabaseTenantSettings;

/**
 * Store defaults for a new brand, taken from the tenant's provisioning record. Idempotent:
 * existing values are never overwritten, so re-running provisioning cannot undo a change a
 * brand has made since.
 */
final class DefaultSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = tenant();

        $defaults = [
            'store' => [
                'name' => $tenant?->getAttribute('name') ?? 'Store',
                'currency' => $tenant?->getAttribute('default_currency') ?? 'PKR',
                'timezone' => $tenant?->getAttribute('default_timezone') ?? 'Asia/Karachi',
                'locale' => $tenant?->getAttribute('default_locale') ?? 'en',
                'country' => 'PK',
                'contact_email' => null,
                'contact_phone' => null,
            ],
            'theme' => [
                'id' => $tenant?->getAttribute('theme_id') ?? 'default',
                'tokens' => (object) [],
            ],
            'tax' => [
                // Prices in Pakistan are customarily displayed tax-inclusive.
                'mode' => 'inclusive',
            ],
            'seo' => [
                'robots_allow_indexing' => true,
                'title_suffix' => $tenant?->getAttribute('name'),
            ],
            'mail' => [
                'from_name' => $tenant?->getAttribute('name'),
                'from_address' => null,
            ],
        ];

        foreach ($defaults as $group => $values) {
            foreach ($values as $key => $value) {
                Setting::query()->firstOrCreate(
                    ['group' => $group, 'key' => $key],
                    ['value' => $value, 'is_encrypted' => false],
                );
            }
        }

        Cache::forget(DatabaseTenantSettings::CACHE_KEY);
    }
}
