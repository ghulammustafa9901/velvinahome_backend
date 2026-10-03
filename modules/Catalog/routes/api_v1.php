<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Catalog\Http\Controllers\V1\CategoryController;
use Modules\Catalog\Http\Controllers\V1\ProductController;
use Modules\Catalog\Http\Controllers\V1\SearchController;

Route::middleware('throttle:catalog')->group(function (): void {
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/by-slug/{slug}', [ProductController::class, 'bySlug'])->where('slug', '[a-z0-9-]+')->name('products.by-slug');
    Route::get('products/{ulid}', [ProductController::class, 'show'])->where('ulid', '[0-9A-HJKMNP-TV-Z]{26}')->name('products.show');
    Route::get('categories', [CategoryController::class, 'tree'])->name('categories.index');
    Route::get('categories/{slug}', [CategoryController::class, 'show'])->name('categories.show');
    Route::get('collections/{slug}', [CategoryController::class, 'collection'])->name('collections.show');
});

// Development only: without object storage, media is streamed from the local disk. In every
// real environment media is served by the CDN.
if (config('filesystems.disks.media.driver') === 'local') {
    Route::get('media/{path}', fn (string $path) => Illuminate\Support\Facades\Storage::disk('media')->response($path))
        ->where('path', '[A-Za-z0-9/_.-]+')
        ->name('media.local');
}

Route::middleware('throttle:search')->group(function (): void {
    Route::get('search', [SearchController::class, 'search'])->name('search');
    Route::get('search/suggest', [SearchController::class, 'suggest'])->name('search.suggest');
});
