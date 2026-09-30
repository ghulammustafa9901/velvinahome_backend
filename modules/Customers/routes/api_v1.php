<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Customers\Http\Controllers\V1\AddressController;
use Modules\Customers\Http\Controllers\V1\WishlistController;

Route::middleware(['auth:customer', 'throttle:api'])->prefix('account')->name('account.')->group(function (): void {
    Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
    Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
    Route::put('addresses/{ulid}', [AddressController::class, 'update'])->name('addresses.update');
    Route::delete('addresses/{ulid}', [AddressController::class, 'destroy'])->name('addresses.destroy');
});

Route::middleware(['feature:wishlist', 'auth:customer', 'throttle:api'])->prefix('wishlist')->name('wishlist.')->group(function (): void {
    Route::get('items', [WishlistController::class, 'index'])->name('items.index');
    Route::post('items', [WishlistController::class, 'store'])->name('items.store');
    Route::delete('items/{ulid}', [WishlistController::class, 'destroy'])->name('items.destroy');
});
