<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\V1\OrderController;

Route::middleware('throttle:api')->group(function (): void {
    Route::get('orders/{ulid}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('orders/{ulid}/payment-proof', [OrderController::class, 'submitPaymentProof'])
        ->middleware('throttle:checkout')
        ->name('orders.payment-proof');

    Route::get('account/orders', [OrderController::class, 'index'])->middleware('auth:customer')->name('account.orders.index');
    Route::get('account/orders/{ulid}', [OrderController::class, 'show'])->middleware('auth:customer')->name('account.orders.show');
});
