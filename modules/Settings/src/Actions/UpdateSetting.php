<?php

declare(strict_types=1);

namespace Modules\Settings\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Modules\Settings\Models\Setting;
use Modules\Settings\Support\DatabaseTenantSettings;
use Modules\Support\Contracts\Action;

/**
 * Writes one tenant setting. Encrypted settings (integration secrets, API keys) are stored as
 * ciphertext so the raw column is unreadable without APP_KEY (spec §8).
 *
 * `$actor` is null for system callers (provisioning); a staff actor must hold
 * settings.manage, checked here so every caller is held to it (spec §6.2).
 */
final class UpdateSetting implements Action
{
    public function handle(string $group, string $key, mixed $value, bool $encrypted = false, ?Authenticatable $actor = null): Setting
    {
        if ($actor !== null && (! $actor instanceof Authorizable || ! $actor->can('settings.manage'))) {
            throw new AuthorizationException('Changing settings requires the settings.manage permission.');
        }

        $stored = $encrypted ? Crypt::encryptString(is_string($value) ? $value : (string) json_encode($value)) : $value;

        $setting = Setting::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $stored, 'is_encrypted' => $encrypted],
        );

        Cache::forget(DatabaseTenantSettings::CACHE_KEY);

        return $setting;
    }
}
