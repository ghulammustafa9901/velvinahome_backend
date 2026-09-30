<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PlatformPanelProvider;
use Modules\Cart\Providers\CartServiceProvider;
use Modules\Catalog\Providers\CatalogServiceProvider;
use Modules\Checkout\Providers\CheckoutServiceProvider;
use Modules\Content\Providers\ContentServiceProvider;
use Modules\Customers\Providers\CustomersServiceProvider;
use Modules\Identity\Providers\IdentityServiceProvider;
use Modules\Integrations\Providers\IntegrationsServiceProvider;
use Modules\Inventory\Providers\InventoryServiceProvider;
use Modules\Notifications\Providers\NotificationsServiceProvider;
use Modules\Orders\Providers\OrdersServiceProvider;
use Modules\Payments\Providers\PaymentsServiceProvider;
use Modules\Platform\Providers\PlatformServiceProvider;
use Modules\Pricing\Providers\PricingServiceProvider;
use Modules\Reporting\Providers\ReportingServiceProvider;
use Modules\Reviews\Providers\ReviewsServiceProvider;
use Modules\Seo\Providers\SeoServiceProvider;
use Modules\Settings\Providers\SettingsServiceProvider;
use Modules\Shipping\Providers\ShippingServiceProvider;
use Modules\Support\Providers\SupportServiceProvider;
use Modules\Tax\Providers\TaxServiceProvider;

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
    TaxServiceProvider::class,
    CustomersServiceProvider::class,
    ShippingServiceProvider::class,
    PricingServiceProvider::class,
    CartServiceProvider::class,
    PaymentsServiceProvider::class,
    OrdersServiceProvider::class,
    CheckoutServiceProvider::class,
    NotificationsServiceProvider::class,
    ContentServiceProvider::class,
    ReviewsServiceProvider::class,
    ReportingServiceProvider::class,
    AppServiceProvider::class,
    PlatformPanelProvider::class,
    AdminPanelProvider::class,
];
