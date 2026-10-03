<?php

declare(strict_types=1);

namespace Modules\Settings\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Modules\Settings\Contracts\StoreSettings;
use Modules\Settings\Models\Setting;

final class DatabaseStoreSettings implements StoreSettings
{
    public const CACHE_KEY = 'settings:all';

    public function get(string $key, mixed $default = null): mixed
    {
        [$group, $name] = $this->split($key);

        $row = $this->all()[$group][$name] ?? null;

        if ($row === null) {
            return $default;
        }

        return $row['encrypted'] ? Crypt::decryptString((string) $row['value']) : $row['value'];
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function group(string $group): array
    {
        $values = [];

        foreach ($this->all()[$group] ?? [] as $name => $row) {
            if (! $row['encrypted']) {
                $values[$name] = $row['value'];
            }
        }

        return $values;
    }

    public function storefrontUrl(): ?string
    {
        $url = rtrim(trim($this->string('store.url')), '/');
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true) && is_string(parse_url($url, PHP_URL_HOST)) ? $url : null;
    }

    /**
     * Cached until a setting changes (UpdateSetting forgets the key).
     *
     * @return array<string, array<string, array{value: mixed, encrypted: bool}>>
     */
    private function all(): array
    {
        /** @var array<string, array<string, array{value: mixed, encrypted: bool}>> */
        return Cache::remember(self::CACHE_KEY, now()->addHour(), function (): array {
            $settings = [];

            foreach (Setting::query()->get() as $setting) {
                $settings[$setting->group][$setting->key] = [
                    'value' => $setting->value,
                    'encrypted' => $setting->is_encrypted,
                ];
            }

            return $settings;
        });
    }

    /**
     * @return array{string, string}
     */
    private function split(string $key): array
    {
        $parts = explode('.', $key, 2);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException("Setting keys are 'group.key', got [{$key}].");
        }

        return [$parts[0], $parts[1]];
    }
}
