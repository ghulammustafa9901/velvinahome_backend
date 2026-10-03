<?php

declare(strict_types=1);

namespace Modules\Settings\Contracts;

/**
 * The store's feature flags, switched on and off in the admin (the `features` settings group).
 * Unknown flags are off: a feature must be switched on deliberately, never by a typo.
 */
interface FeatureFlags
{
    public function enabled(string $feature): bool;

    /**
     * Every known flag with its current state, for the storefront config.
     *
     * @return array<string, bool>
     */
    public function all(): array;
}
