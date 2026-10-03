<?php

declare(strict_types=1);

namespace Modules\Settings\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Str;
use Modules\Settings\Contracts\StoreSettings;
use Modules\Settings\Support\StoreLogo;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;
use Modules\Support\Media\SafeImage;

/**
 * Replaces (or, with a null path, removes) the store logo. The upload is made safe by
 * SafeImage, stored under a random name, and the previous file is deleted once the setting
 * points at the new one.
 */
final class SaveStoreLogo implements Action
{
    use ChecksPermission;

    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_DIMENSION = 2000;

    public function __construct(
        private readonly StoreSettings $settings,
        private readonly UpdateSetting $update,
    ) {}

    /**
     * @return string|null the stored path
     */
    public function handle(?string $uploadedPath, ?Authorizable $actor = null): ?string
    {
        $this->authorizePermission($actor, 'settings.manage');

        $previous = $this->settings->string('store.logo');
        $stored = null;

        if ($uploadedPath !== null) {
            $image = SafeImage::reEncode($uploadedPath, 'logo', self::MAX_BYTES, self::MAX_DIMENSION);

            try {
                $stored = StoreLogo::DIRECTORY.'/logo-'.strtolower((string) Str::ulid()).'.webp';
                StoreLogo::disk()->put($stored, (string) file_get_contents($image['path']), 'public');
            } finally {
                @unlink($image['path']);
            }
        }

        $this->update->handle('store', 'logo', $stored);

        // Only ever a file this Action wrote, never an arbitrary path a setting might hold.
        if (str_starts_with($previous, StoreLogo::DIRECTORY.'/') && $previous !== $stored) {
            StoreLogo::disk()->delete($previous);
        }

        return $stored;
    }
}
