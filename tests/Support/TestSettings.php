<?php

declare(strict_types=1);

namespace Tests\Support;

use Modules\Settings\Actions\UpdateSetting;

/**
 * Store settings for tests, written through the same Action the admin uses.
 */
final class TestSettings
{
    public static function feature(string $flag, bool $enabled): void
    {
        app(UpdateSetting::class)->handle('features', $flag, $enabled);
    }

    public static function set(string $key, mixed $value): void
    {
        [$group, $name] = explode('.', $key, 2);

        app(UpdateSetting::class)->handle($group, $name, $value);
    }
}
