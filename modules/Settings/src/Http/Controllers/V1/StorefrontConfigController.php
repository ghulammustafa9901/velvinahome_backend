<?php

declare(strict_types=1);

namespace Modules\Settings\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Modules\Platform\Contracts\FeatureFlags;
use Modules\Settings\Contracts\TenantSettings;

/**
 * GET /api/v1/storefront/config — what the Next.js storefront needs before rendering any page
 * for this brand: theme, tokens, flags, locale, currency (spec §7.1). Public and cacheable;
 * nothing here is sensitive.
 */
final class StorefrontConfigController
{
    public function __invoke(TenantSettings $settings, FeatureFlags $flags): JsonResponse
    {
        return response()
            ->json([
                'data' => [
                    'store' => [
                        'name' => $settings->string('store.name'),
                        'currency' => $settings->string('store.currency', 'PKR'),
                        'locale' => $settings->string('store.locale', 'en'),
                        'timezone' => $settings->string('store.timezone', 'Asia/Karachi'),
                        'country' => $settings->string('store.country', 'PK'),
                    ],
                    'theme' => [
                        'id' => $settings->string('theme.id', 'default'),
                        'tokens' => (object) ($settings->get('theme.tokens') ?? []),
                    ],
                    'tax' => [
                        'mode' => $settings->string('tax.mode', 'inclusive'),
                    ],
                    'seo' => [
                        'allow_indexing' => (bool) $settings->get('seo.robots_allow_indexing', true),
                        'title_suffix' => $settings->get('seo.title_suffix'),
                    ],
                    'features' => $flags->all(),
                ],
            ])
            ->setPublic()
            ->setMaxAge(60)
            ->setSharedMaxAge(300);
    }
}
