<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Notifications\Http\Controllers\V1\EmailPreferenceController;

Route::post('email-preferences/unsubscribe', [EmailPreferenceController::class, 'unsubscribe'])
    ->middleware('throttle:auth')
    ->name('email-preferences.unsubscribe');
