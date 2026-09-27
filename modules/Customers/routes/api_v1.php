<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Customers\Http\Controllers\V1\AddressController;

Route::middleware(['auth:customer', 'throttle:api'])->prefix('account')->name('account.')->group(function (): void {
    Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
    Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
    Route::put('addresses/{ulid}', [AddressController::class, 'update'])->name('addresses.update');
    Route::delete('addresses/{ulid}', [AddressController::class, 'destroy'])->name('addresses.destroy');
});
