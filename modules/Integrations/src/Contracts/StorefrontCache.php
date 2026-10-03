<?php

declare(strict_types=1);

namespace Modules\Integrations\Contracts;

/**
 * Tells the storefront that cached pages are stale (spec §6.13, §7.4). Tags are the
 * storefront's unscoped ISR tags: "products", "product:<slug>", "categories",
 * "category:<slug>", "collection:<slug>", "pages", "page:<slug>", "menus", "config",
 * "reviews:<product ulid>". Delivery goes through the outbox, after commit, with retries.
 */
interface StorefrontCache
{
    /**
     * @param  list<string>  $tags
     */
    public function invalidate(array $tags): void;
}
