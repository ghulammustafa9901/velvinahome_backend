<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PlatformPanelProvider;
use Modules\Catalog\Providers\CatalogServiceProvider;
use Modules\Identity\Providers\IdentityServiceProvider;
use Modules\Integrations\Providers\IntegrationsServiceProvider;
use Modules\Inventory\Providers\InventoryServiceProvider;
use Modules\Platform\Providers\PlatformServiceProvider;
use Modules\Seo\Providers\SeoServiceProvider;
use Modules\Settings\Providers\SettingsServiceProvider;
use Modules\Support\Providers\SupportServiceProvider;

/*
| Modules are registered here explicitly (ADR-0011). Order matters only for Support, the
| shared kernel, which comes first. The platform panel is registered before the tenant
| admin panel so its host-bound routes are matched first.
*/

return [
    SupportServiceProvider::class,
    PlatformServiceProvider::class,
    IdentityServiceProvider::class,
    SettingsServiceProvider::class,
    IntegrationsServiceProvider::class,
    SeoServiceProvider::class,
    CatalogServiceProvider::class,
    InventoryServiceProvider::class,
    AppServiceProvider::class,
    PlatformPanelProvider::class,
    AdminPanelProvider::class,
];
