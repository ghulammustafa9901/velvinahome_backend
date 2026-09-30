<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Reviews\Http\Controllers\V1\ReviewController;

Route::middleware('feature:reviews')->group(function (): void {
    Route::get('products/{product}/reviews', [ReviewController::class, 'index'])
        ->where('product', '[0-9A-HJKMNP-TV-Z]{26}')
        ->middleware('throttle:catalog')
        ->name('reviews.index');
    Route::post('products/{product}/reviews', [ReviewController::class, 'store'])
        ->where('product', '[0-9A-HJKMNP-TV-Z]{26}')
        ->middleware(['auth:customer', 'throttle:checkout'])
        ->name('reviews.store');
});
