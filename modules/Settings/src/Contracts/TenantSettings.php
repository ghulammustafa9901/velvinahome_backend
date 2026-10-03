<?php

declare(strict_types=1);

namespace Modules\Settings\Contracts;

/**
 * The current tenant's settings, addressed as "group.key" (e.g. "store.currency").
 * Reads are cached per tenant; writes go through the Settings Actions and bust the cache.
 */
interface TenantSettings
{
    public function get(string $key, mixed $default = null): mixed;

    public function string(string $key, string $default = ''): string;

    /**
     * @return array<string, mixed> every non-encrypted setting of a group
     */
    public function group(string $group): array;

    /**
     * The storefront's public origin (`store.url`, e.g. "https://velvinahome.com"), without a
     * trailing slash, for links in emails and for storefront revalidation. Null until it is
     * set to an absolute http(s) URL. Never derived from request input, so a link in an email
     * can never be pointed at another site.
     */
    public function storefrontUrl(): ?string;
}
