<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Controllers\V1\AccountController;
use Modules\Identity\Http\Controllers\V1\CustomerAuthController;

Route::prefix('auth')->name('auth.')->middleware('throttle:auth')->group(function (): void {
    // Turnstile where a bot gains something: new accounts, password guessing, reset spam.
    Route::post('register', [CustomerAuthController::class, 'register'])->middleware('human')->name('register');
    Route::post('login', [CustomerAuthController::class, 'login'])->middleware('human')->name('login');
    Route::post('forgot-password', [AccountController::class, 'forgotPassword'])->middleware('human')->name('forgot-password');
    Route::post('reset-password', [AccountController::class, 'resetPassword'])->name('reset-password');
});

Route::middleware(['auth:customer', 'throttle:api'])->group(function (): void {
    Route::post('auth/logout', [CustomerAuthController::class, 'logout'])->name('auth.logout');
    // Kept for existing clients; /account/profile is the documented path.
    Route::get('me', [CustomerAuthController::class, 'me'])->name('me');
    Route::get('account/profile', [AccountController::class, 'profile'])->name('account.profile');
    Route::patch('account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
    Route::put('account/password', [AccountController::class, 'changePassword'])->middleware('throttle:auth')->name('account.password');
});
