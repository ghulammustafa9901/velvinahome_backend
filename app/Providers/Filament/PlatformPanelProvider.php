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
use Modules\Platform\Http\Middleware\EnsurePlatformHost;
use Modules\Platform\Http\Middleware\ScopeSessionToTenant;

/**
 * The platform (landlord) control panel, for the Platform Owner's team only (spec §4.9).
 * Bound to the platform host: on any tenant host its routes do not exist (404, not 403).
 */
final class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('platform')
            ->path('')
            ->domain((string) config('platform.host'))
            ->authGuard('platform')
            ->login()
            ->multiFactorAuthentication(
                [AppAuthentication::make()->recoverable()],
                isRequired: fn (): bool => (bool) config('platform.staff.two_factor_required'),
            )
            ->brandName((string) config('platform.name'))
            ->colors(['primary' => Color::Indigo])
            ->discoverResources(in: base_path('modules/Platform/src/Filament/Resources'), for: 'Modules\\Platform\\Filament\\Resources')
            ->discoverPages(in: base_path('modules/Platform/src/Filament/Pages'), for: 'Modules\\Platform\\Filament\\Pages')
            ->pages([Dashboard::class])
            ->middleware([
                EnsurePlatformHost::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ScopeSessionToTenant::class,
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
