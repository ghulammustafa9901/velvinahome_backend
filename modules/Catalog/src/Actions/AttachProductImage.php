<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use finfo;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Catalog\Models\Product;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Adds an image to a product, safely (spec §6.9):
 *
 *  - the type is decided by the file's content, never by its name or the browser's claim;
 *  - only JPEG, PNG, WebP and AVIF raster images are accepted — never SVG, which can carry
 *    script;
 *  - size and dimensions are capped;
 *  - the image is decoded and re-encoded as WebP, which drops EXIF (GPS, device data) and
 *    anything smuggled after the image data;
 *  - the stored name is random, so nothing about the uploader's file name reaches a URL.
 */
final class AttachProductImage implements Action
{
    use ChecksPermission;

    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_DIMENSION = 8000;

    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];

    public function handle(Product $product, string $path, ?string $alt = null, ?Authorizable $actor = null): Media
    {
        $this->authorizePermission($actor, 'products.manage');

        if (! is_file($path) || filesize($path) === 0 || filesize($path) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['image' => __('Images must be between 1 byte and 10 MB.')]);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = @getimagesize($path);

        if (! in_array($mime, self::ALLOWED, true) || $size === false) {
            throw ValidationException::withMessages(['image' => __('Only JPEG, PNG, WebP or AVIF images are accepted.')]);
        }

        if ($size[0] > self::MAX_DIMENSION || $size[1] > self::MAX_DIMENSION) {
            throw ValidationException::withMessages(['image' => __('Images may be at most :max pixels on each side.', ['max' => self::MAX_DIMENSION])]);
        }

        $reEncoded = $this->reEncode($path);

        try {
            return $product->addMedia($reEncoded)
                ->usingFileName(strtolower((string) Str::ulid()).'.webp')
                ->usingName(mb_substr($alt ?? $product->title, 0, 200))
                ->withCustomProperties(['alt' => $alt ?? $product->title, 'width' => $size[0], 'height' => $size[1]])
                ->toMediaCollection('images');
        } finally {
            if (is_file($reEncoded)) {
                @unlink($reEncoded);
            }
        }
    }

    private function reEncode(string $path): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($path));

        if ($image === false) {
            throw ValidationException::withMessages(['image' => __('The image could not be read.')]);
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $target = tempnam(sys_get_temp_dir(), 'img').'.webp';
        imagewebp($image, $target, 88);
        imagedestroy($image);

        return $target;
    }
}
