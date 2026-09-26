<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Modules\Platform\Http\Controllers\BrandSwitchController;
use Modules\Platform\Http\Controllers\HandoffController;
use Modules\Platform\Http\Middleware\ScopeSessionToTenant;

/*
| Routes on tenant admin hosts that are not Filament pages. The hand-off is rate limited and
| answers 404 for anything but a valid, unused token for this very tenant.
*/

Route::middleware([
    'tenant:admin',
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ScopeSessionToTenant::class,
    'throttle:auth',
])->group(function (): void {
    Route::get('_handoff/{kind}/{token}', HandoffController::class)
        ->whereIn('kind', ['support', 'brand'])
        ->where('token', '[a-f0-9]{64}')
        ->name('admin.handoff');

    Route::get('_switch/{tenant}', BrandSwitchController::class)
        ->middleware('auth:staff')
        ->where('tenant', '[0-9A-HJKMNP-TV-Z]{26}')
        ->name('admin.brand-switch');
});
