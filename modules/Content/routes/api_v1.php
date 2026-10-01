<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Content\Http\Controllers\V1\ArticleController;
use Modules\Content\Http\Controllers\V1\ContentController;
use Modules\Content\Http\Controllers\V1\ContentEntryController;

Route::middleware('throttle:catalog')->group(function (): void {
    Route::get('pages/{slug}', [ContentController::class, 'page'])->where('slug', '[a-z0-9-]+')->name('pages.show');
    Route::get('menus/{key}', [ContentController::class, 'menu'])->where('key', '[a-z0-9_-]+')->name('menus.show');

    Route::middleware('feature:blog')->group(function (): void {
        Route::get('articles', [ArticleController::class, 'index'])->name('articles.index');
        Route::get('articles/{slug}', [ArticleController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('articles.show');
    });

    Route::get('content/{type}', [ContentEntryController::class, 'index'])->where('type', '[a-z][a-z0-9-]*')->name('content.index');
    Route::get('content/{type}/{slug}', [ContentEntryController::class, 'show'])->where(['type' => '[a-z][a-z0-9-]*', 'slug' => '[a-z0-9-]+'])->name('content.show');
});
