<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Checkout\Http\Controllers\V1\CheckoutController;

Route::middleware('throttle:checkout')->prefix('checkout')->name('checkout.')->group(function (): void {
    Route::post('quote', [CheckoutController::class, 'quote'])->name('quote');
    Route::post('orders', [CheckoutController::class, 'place'])->name('place');
});
