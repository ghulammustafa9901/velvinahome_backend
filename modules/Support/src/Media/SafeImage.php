<?php

declare(strict_types=1);

namespace Modules\Support\Media;

use finfo;
use Illuminate\Validation\ValidationException;

/**
 * Untrusted image uploads, made safe (spec §6.9):
 *
 *  - the type is decided by the file's content, never by its name or the browser's claim;
 *  - only JPEG, PNG, WebP and AVIF raster images are accepted — never SVG, which can carry
 *    script;
 *  - size and dimensions are capped;
 *  - the image is decoded and re-encoded as WebP, which drops EXIF (GPS, device data) and
 *    anything smuggled after the image data.
 *
 * Callers store the result under a random name, so nothing about the uploader's file name
 * reaches a URL.
 */
final class SafeImage
{
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];

    /**
     * @return array{path: string, width: int, height: int} a re-encoded WebP temp file the caller must delete
     */
    public static function reEncode(string $path, string $field, int $maxBytes, int $maxDimension): array
    {
        if (! is_file($path) || filesize($path) === 0 || filesize($path) > $maxBytes) {
            throw ValidationException::withMessages([$field => __('Images must be between 1 byte and :max MB.', ['max' => intdiv($maxBytes, 1024 * 1024)])]);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = @getimagesize($path);

        if (! in_array($mime, self::ALLOWED, true) || $size === false) {
            throw ValidationException::withMessages([$field => __('Only JPEG, PNG, WebP or AVIF images are accepted.')]);
        }

        if ($size[0] > $maxDimension || $size[1] > $maxDimension) {
            throw ValidationException::withMessages([$field => __('Images may be at most :max pixels on each side.', ['max' => $maxDimension])]);
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));

        if ($image === false) {
            throw ValidationException::withMessages([$field => __('The image could not be read.')]);
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $target = tempnam(sys_get_temp_dir(), 'img').'.webp';
        imagewebp($image, $target, 88);
        imagedestroy($image);

        return ['path' => $target, 'width' => $size[0], 'height' => $size[1]];
    }
}
