<?php

declare(strict_types=1);

namespace Modules\Settings\Support;

use Modules\Settings\Contracts\FeatureFlags;
use Modules\Settings\Contracts\StoreSettings;

/**
 * Flags are ordinary settings (`features.<flag>`), so they are cached, audited and invalidated
 * exactly like every other setting, and changed only through UpdateSetting.
 */
final class SettingsFeatureFlags implements FeatureFlags
{
    /**
     * Every flag a module gates on, with its default for a new store. Listed here so the
     * storefront config always carries the full set.
     *
     * @var array<string, bool>
     */
    public const DEFAULTS = [
        'wishlist' => false,
        'abandoned_cart' => false,
        'reviews' => false,
        'blog' => false,
        'multi_location_inventory' => false,
        'guest_checkout' => true,
        'customer_accounts' => true,
    ];

    public function __construct(private readonly StoreSettings $settings) {}

    public function enabled(string $feature): bool
    {
        return $this->all()[$feature] ?? false;
    }

    public function all(): array
    {
        $flags = self::DEFAULTS;

        foreach ($this->settings->group('features') as $key => $enabled) {
            $flags[$key] = (bool) $enabled;
        }

        ksort($flags);

        return $flags;
    }
}
