<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Content\Http\Controllers\V1\ContentController;

Route::middleware('throttle:catalog')->group(function (): void {
    Route::get('pages/{slug}', [ContentController::class, 'page'])->where('slug', '[a-z0-9-]+')->name('pages.show');
    Route::get('menus/{key}', [ContentController::class, 'menu'])->where('key', '[a-z0-9_-]+')->name('menus.show');
});
