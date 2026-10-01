<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

use Illuminate\Contracts\Cache\Repository as Cache;
use Modules\Platform\Contracts\FeatureFlags;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantFeatureFlag;
use Stancl\Tenancy\Facades\GlobalCache;

/**
 * Plan defaults, overridden per tenant. Unknown flags are off: a feature must be switched on
 * deliberately, never by a typo.
 */
final class PlanFeatureFlags implements FeatureFlags
{
    /**
     * Platform-wide defaults for tenants without a plan. Every flag a module gates on is
     * listed here so the storefront config always carries the full set.
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

    /** @var array<string, array<string, bool>> */
    private array $resolved = [];

    private readonly Cache $cache;

    public function __construct()
    {
        // The landlord-level (untagged) cache, deliberately: the platform panel changes
        // flags from central context and must be able to invalidate them. Keys carry the
        // tenant id, so entries never collide.
        $this->cache = GlobalCache::store();
    }

    public function enabled(string $feature): bool
    {
        return $this->all()[$feature] ?? false;
    }

    public function all(): array
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return self::DEFAULTS;
        }

        return $this->resolved[$tenant->id] ??= $this->cache->remember(
            $this->cacheKey($tenant->id),
            now()->addMinutes(10),
            fn (): array => $this->compute($tenant),
        );
    }

    public function forget(string $tenantId): void
    {
        unset($this->resolved[$tenantId]);
        $this->cache->forget($this->cacheKey($tenantId));
    }

    /**
     * @return array<string, bool>
     */
    private function compute(Tenant $tenant): array
    {
        $flags = self::DEFAULTS;

        // A fresh read, not the relation possibly cached on a long-lived tenant instance: a plan
        // change must show once the flags are forgotten.
        foreach ($tenant->plan()->first()->default_features ?? [] as $key => $enabled) {
            $flags[(string) $key] = (bool) $enabled;
        }

        $overrides = TenantFeatureFlag::query()->where('tenant_id', $tenant->id)->get();

        foreach ($overrides as $override) {
            $flags[$override->key] = $override->enabled;
        }

        ksort($flags);

        return $flags;
    }

    private function cacheKey(string $tenantId): string
    {
        return 'platform:feature-flags:'.$tenantId;
    }
}
