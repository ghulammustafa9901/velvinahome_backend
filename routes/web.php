<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| No central web routes
|--------------------------------------------------------------------------
|
| Storefronts are served by the Next.js app. This application serves only:
|   - the tenant admin panel on admin.<brand>   (Filament, `tenant:admin`)
|   - the storefront API on api.<brand>         (module api_v1 routes, `tenant:api`)
|   - the platform panel on the platform host   (Filament, `platform.host`)
|   - the health check, on any host
|
*/

use Illuminate\Support\Facades\Route;
use Modules\Support\Http\HealthController;

// Deep health check for uptime monitoring (spec §9).
Route::get('health', HealthController::class)->name('health');
