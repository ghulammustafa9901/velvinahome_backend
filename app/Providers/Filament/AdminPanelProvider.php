<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\Settings\Contracts\StoreSettings;
use Modules\Support\Http\EnforceSessionAge;
use Throwable;

/**
 * The store admin (spec §6.12), on the admin host (ADMIN_DOMAIN; any host when empty). Staff
 * must enrol an authenticator app before any admin page loads (spec §6.2).
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $domain = config('store.admin_domain');

        return $panel
            ->id('admin')
            ->path('')
            ->domain(is_string($domain) && $domain !== '' ? $domain : null)
            ->authGuard('staff')
            ->login()
            ->multiFactorAuthentication(
                [AppAuthentication::make()->recoverable()],
                isRequired: fn (): bool => (bool) config('store.staff.two_factor_required'),
            )
            ->brandName(fn (): string => self::storeName())
            ->colors(['primary' => Color::Slate])
            ->discoverResources(in: base_path('modules/Identity/src/Filament/Resources'), for: 'Modules\\Identity\\Filament\\Resources')
            ->discoverResources(in: base_path('modules/Settings/src/Filament/Resources'), for: 'Modules\\Settings\\Filament\\Resources')
            ->discoverResources(in: base_path('modules/Catalog/src/Filament/Resources'), for: 'Modules\\Catalog\\Filament\\Resources')
            ->discoverResources(in: base_path('modules/Inventory/src/Filament/Resources'), for: 'Modules\\Inventory\\Filament\\Resources')
            ->discoverResources(in: base_path('modules/Orders/src/Filament/Resources'), for: 'Modules\\Orders\\Filament\\Resources')
            ->discoverResources(in: base_path('modules/Pricing/src/Filament/Resources'), for: 'Modules\\Pricing\\Filament\\Resources')
            ->discoverResources(in: base_path('modules/Shipping/src/Filament/Resources'), for: 'Modules\\Shipping\\Filament\\Resources')
            ->discoverResources(in: base_path('modules/Tax/src/Filament/Resources'), for: 'Modules\\Tax\\Filament\\Resources')
            ->discoverResources(in: base_path('modules/Payments/src/Filament/Resources'), for: 'Modules\\Payments\\Filament\\Resources')
            ->discoverResources(in: base_path('modules/Notifications/src/Filament/Resources'), for: 'Modules\\Notifications\\Filament\\Resources')
            ->discoverResources(in: base_path('modules/Content/src/Filament/Resources'), for: 'Modules\\Content\\Filament\\Resources')
            ->discoverResources(in: base_path('modules/Reviews/src/Filament/Resources'), for: 'Modules\\Reviews\\Filament\\Resources')
            ->discoverResources(in: base_path('modules/Imports/src/Filament/Resources'), for: 'Modules\\Imports\\Filament\\Resources')
            ->discoverPages(in: base_path('modules/Settings/src/Filament/Pages'), for: 'Modules\\Settings\\Filament\\Pages')
            ->discoverPages(in: base_path('modules/Reporting/src/Filament/Pages'), for: 'Modules\\Reporting\\Filament\\Pages')
            ->discoverWidgets(in: base_path('modules/Reporting/src/Filament/Widgets'), for: 'Modules\\Reporting\\Filament\\Widgets')
            ->pages([Dashboard::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnforceSessionAge::class.':staff',
            ])
            ->databaseNotifications(false);
    }

    /**
     * The store name from settings; the login page must still render before the database is
     * migrated or reachable.
     */
    private static function storeName(): string
    {
        try {
            return app(StoreSettings::class)->string('store.name') ?: 'Store admin';
        } catch (Throwable) {
            return 'Store admin';
        }
    }
}
