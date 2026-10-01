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
| Anything else is a 404 by design: an unknown host never falls back to a tenant.
|
*/

use Illuminate\Support\Facades\Route;
use Modules\Platform\Http\Controllers\HealthController;

// Deep health check for uptime monitoring, on the platform host only (spec §9).
Route::get('health', HealthController::class)->middleware('platform.host')->name('health');
