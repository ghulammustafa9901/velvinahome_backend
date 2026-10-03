<?php

declare(strict_types=1);

namespace Tests\Support;

use Modules\Platform\Models\Tenant;
use Modules\Settings\Actions\UpdateSetting;

/**
 * Store settings for tests, written through the same Action the admin uses.
 */
final class TestSettings
{
    public static function feature(Tenant $brand, string $flag, bool $enabled): void
    {
        $brand->run(fn () => app(UpdateSetting::class)->handle('features', $flag, $enabled));
    }

    public static function set(Tenant $brand, string $key, mixed $value): void
    {
        [$group, $name] = explode('.', $key, 2);

        $brand->run(fn () => app(UpdateSetting::class)->handle($group, $name, $value));
    }
}
