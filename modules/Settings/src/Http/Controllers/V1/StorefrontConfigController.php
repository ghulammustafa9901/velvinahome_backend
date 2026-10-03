<?php

declare(strict_types=1);

namespace Modules\Settings\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Modules\Settings\Contracts\FeatureFlags;
use Modules\Settings\Contracts\StoreSettings;
use Modules\Settings\Support\StoreLogo;
use Modules\Support\ValueObjects\StringMap;

/**
 * GET /api/v1/storefront/config — what the Next.js storefront needs before rendering any page:
 * store identity, theme, tokens, flags, locale, currency (spec §7.1). Public and cacheable;
 * nothing here is sensitive.
 */
final class StorefrontConfigController
{
    public function __invoke(StoreSettings $settings, FeatureFlags $flags): JsonResponse
    {
        return response()
            ->json([
                'data' => [
                    'store' => [
                        'name' => $settings->string('store.name'),
                        'url' => $settings->storefrontUrl(),
                        'logo_url' => StoreLogo::url($settings->string('store.logo')),
                        'currency' => $settings->string('store.currency', 'PKR'),
                        'locale' => $settings->string('store.locale', 'en'),
                        'timezone' => $settings->string('store.timezone', 'Asia/Karachi'),
                        'country' => $settings->string('store.country', 'PK'),
                    ],
                    'theme' => [
                        'id' => $settings->string('theme.id', 'default'),
                        'tokens' => StringMap::from((array) ($settings->get('theme.tokens') ?? [])),
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
