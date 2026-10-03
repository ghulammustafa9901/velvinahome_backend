<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Str;
use Modules\Catalog\Models\Product;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;
use Modules\Support\Media\SafeImage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Adds an image to a product, safely: content-sniffed type, raster only, capped size, re-encoded
 * to WebP (SafeImage, spec §6.9), stored under a random name.
 */
final class AttachProductImage implements Action
{
    use ChecksPermission;

    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_DIMENSION = 8000;

    public function handle(Product $product, string $path, ?string $alt = null, ?Authorizable $actor = null): Media
    {
        $this->authorizePermission($actor, 'products.manage');

        $image = SafeImage::reEncode($path, 'image', self::MAX_BYTES, self::MAX_DIMENSION);

        try {
            return $product->addMedia($image['path'])
                ->usingFileName(strtolower((string) Str::ulid()).'.webp')
                ->usingName(mb_substr($alt ?? $product->title, 0, 200))
                ->withCustomProperties(['alt' => $alt ?? $product->title, 'width' => $image['width'], 'height' => $image['height']])
                ->toMediaCollection('images');
        } finally {
            if (is_file($image['path'])) {
                @unlink($image['path']);
            }
        }
    }
}
