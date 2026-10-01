<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Cart\Http\Controllers\V1\CartController;

Route::middleware('throttle:cart')->prefix('carts')->name('carts.')->group(function (): void {
    Route::post('/', [CartController::class, 'store'])->name('store');
    Route::get('{ulid}', [CartController::class, 'show'])->name('show');
    Route::post('{ulid}/items', [CartController::class, 'addItem'])->name('items.store');
    Route::patch('{ulid}/items/{item}', [CartController::class, 'updateItem'])->name('items.update');
    Route::delete('{ulid}/items/{item}', [CartController::class, 'removeItem'])->name('items.destroy');
    Route::post('{ulid}/coupon', [CartController::class, 'applyCoupon'])->name('coupon.store');
    Route::delete('{ulid}/coupon', [CartController::class, 'removeCoupon'])->name('coupon.destroy');
    Route::post('{ulid}/recover', [CartController::class, 'recover'])->name('recover');
    Route::post('{ulid}/merge', [CartController::class, 'merge'])->middleware('auth:customer')->name('merge');
});
