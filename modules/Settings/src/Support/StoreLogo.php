<?php

declare(strict_types=1);

namespace Modules\Settings\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The store logo lives on the public media disk; the `store.logo` setting holds its path.
 */
final class StoreLogo
{
    public const DIRECTORY = 'store';

    public static function disk(): FilesystemAdapter
    {
        $disk = Storage::disk((string) config('media-library.disk_name'));

        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException('The media disk must be a filesystem adapter.');
        }

        return $disk;
    }

    public static function url(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        return self::disk()->url($path);
    }
}
