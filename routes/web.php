<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| No other web routes
|--------------------------------------------------------------------------
|
| The storefront is served by the Next.js app. This application serves only:
|   - the store admin (Filament; ADMIN_DOMAIN when set)
|   - the storefront API under /api/v1 (module api_v1 routes)
|   - Horizon and the API docs for staff with system.monitor
|   - the health check
|
*/

use Illuminate\Support\Facades\Route;
use Modules\Support\Http\HealthController;

// Deep health check for uptime monitoring (spec §9).
Route::get('health', HealthController::class)->name('health');
