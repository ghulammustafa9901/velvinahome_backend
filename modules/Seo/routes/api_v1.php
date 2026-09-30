<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Seo\Http\Controllers\V1\RedirectController;
use Modules\Seo\Http\Controllers\V1\SitemapController;

Route::middleware('throttle:catalog')->group(function (): void {
    Route::get('redirects/resolve', [RedirectController::class, 'resolve'])->name('redirects.resolve');
    Route::get('sitemap', [SitemapController::class, 'index'])->name('sitemap.index');
    Route::get('sitemap/{chunk}', [SitemapController::class, 'chunk'])->where('chunk', '[a-z_]+-[0-9]+')->name('sitemap.chunk');
});
