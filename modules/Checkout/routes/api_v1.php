<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Checkout\Http\Controllers\V1\CheckoutController;

Route::middleware('throttle:cart')->group(function (): void {
    Route::get('checkout/{cart}/shipping-methods', [CheckoutController::class, 'shippingMethods'])
        ->where('cart', '[0-9A-HJKMNP-TV-Z]{26}')
        ->name('checkout.shipping-methods');
    Route::post('checkout/quote', [CheckoutController::class, 'quote'])->name('checkout.quote');
});

// Fake COD orders are the main abuse: Turnstile (when configured) plus a tight throttle.
Route::middleware(['throttle:checkout', 'human'])->group(function (): void {
    Route::post('checkout/orders', [CheckoutController::class, 'place'])->name('checkout.place');
});
