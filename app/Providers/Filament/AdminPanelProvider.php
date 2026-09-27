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
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\Identity\Contracts\StaffDirectory;
use Modules\Platform\Contracts\OwnerBrands;
use Modules\Platform\Http\Middleware\EnforceImpersonationExpiry;
use Modules\Platform\Http\Middleware\ScopeSessionToTenant;

/**
 * The tenant admin panel, served on each brand's admin host (spec §6.12).
 *
 * Runs INSIDE the tenancy context resolved from the host; Filament's own "tenancy" feature
 * (a single-database team model) is deliberately not used (spec §4.5). Staff must enrol an
 * authenticator app before any admin page loads (spec §6.2).
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('')
            ->authGuard('staff')
            ->login()
            ->multiFactorAuthentication(
                [AppAuthentication::make()->recoverable()],
                isRequired: fn (): bool => (bool) config('platform.staff.two_factor_required'),
            )
            ->brandName(fn (): string => (string) (tenant()?->getAttribute('name') ?? 'Store admin'))
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
            ->discoverPages(in: base_path('modules/Settings/src/Filament/Pages'), for: 'Modules\\Settings\\Filament\\Pages')
            ->pages([Dashboard::class])
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): View => view('filament.brand-switcher', [
                'current' => (string) tenant()?->getAttribute('name'),
                'brands' => ($staffUlid = app(StaffDirectory::class)->currentStaffUlid()) === null
                    ? []
                    : app(OwnerBrands::class)->otherBrandsFor($staffUlid),
            ]))
            ->middleware([
                'tenant:admin',
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ScopeSessionToTenant::class,
                EnforceImpersonationExpiry::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->databaseNotifications(false);
    }
}
