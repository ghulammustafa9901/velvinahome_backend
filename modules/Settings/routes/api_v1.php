<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Settings\Http\Controllers\V1\StorefrontConfigController;

Route::get('storefront/config', StorefrontConfigController::class)->name('storefront.config');
