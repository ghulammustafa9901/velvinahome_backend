<?php

declare(strict_types=1);

namespace Modules\Settings\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Modules\Settings\Models\Setting;
use Modules\Settings\Support\DatabaseStoreSettings;
use Modules\Settings\Support\SettingsFeatureFlags;

/**
 * Store defaults for a new store. Idempotent: existing values are never overwritten, so
 * re-running it cannot undo a change made in the admin since.
 */
final class DefaultSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $name = (string) config('app.name');

        $defaults = [
            'store' => [
                'name' => $name,
                'url' => $this->storefrontUrl(),
                'logo' => null,
                'currency' => 'PKR',
                'timezone' => 'Asia/Karachi',
                'locale' => 'en',
                'country' => 'PK',
                'contact_email' => null,
                'contact_phone' => null,
            ],
            'theme' => [
                'id' => 'default',
                'tokens' => (object) [],
            ],
            'tax' => [
                // Prices in Pakistan are customarily displayed tax-inclusive.
                'mode' => 'inclusive',
            ],
            'seo' => [
                'robots_allow_indexing' => true,
                'title_suffix' => $name,
            ],
            'mail' => [
                'from_name' => $name,
                'from_address' => null,
            ],
            'features' => SettingsFeatureFlags::DEFAULTS,
        ];

        foreach ($defaults as $group => $values) {
            foreach ($values as $key => $value) {
                Setting::query()->firstOrCreate(
                    ['group' => $group, 'key' => $key],
                    ['value' => $value, 'is_encrypted' => false],
                );
            }
        }

        Cache::forget(DatabaseStoreSettings::CACHE_KEY);
    }

    /**
     * STOREFRONT_URL is the install-time default; afterwards the admin owns `store.url`.
     */
    private function storefrontUrl(): ?string
    {
        $configured = config('store.storefront.url');

        return is_string($configured) && $configured !== '' ? rtrim($configured, '/') : null;
    }
}
