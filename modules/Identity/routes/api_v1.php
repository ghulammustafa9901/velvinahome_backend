<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Controllers\V1\CustomerAuthController;

Route::prefix('auth')->name('auth.')->middleware('throttle:auth')->group(function (): void {
    Route::post('register', [CustomerAuthController::class, 'register'])->name('register');
    Route::post('login', [CustomerAuthController::class, 'login'])->name('login');
});

Route::middleware('auth:customer')->group(function (): void {
    Route::post('auth/logout', [CustomerAuthController::class, 'logout'])->name('auth.logout');
    Route::get('me', [CustomerAuthController::class, 'me'])->name('me');
});
