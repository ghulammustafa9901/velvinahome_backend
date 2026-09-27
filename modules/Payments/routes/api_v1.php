<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\Controllers\V1\WebhookController;

// Not rate limited by IP: protected by signature verification and deduplication (api-conventions §8).
Route::post('webhooks/{provider}', WebhookController::class)->where('provider', '[a-z_]+')->name('webhooks');

Route::get('payment-methods', fn (Modules\Payments\Contracts\Payments $payments) => response()->json(['data' => $payments->enabledMethods()]))
    ->middleware('throttle:catalog')
    ->name('payment-methods.index');
