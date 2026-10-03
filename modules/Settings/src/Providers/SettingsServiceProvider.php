<?php

declare(strict_types=1);

namespace Modules\Settings\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Modules\Integrations\Contracts\StorefrontCache;
use Modules\Settings\Contracts\FeatureFlags;
use Modules\Settings\Contracts\OnboardingChecks;
use Modules\Settings\Contracts\StoreSettings;
use Modules\Settings\Models\Setting;
use Modules\Settings\Seeders\DefaultSettingsSeeder;
use Modules\Settings\Support\DatabaseStoreSettings;
use Modules\Settings\Support\LaunchChecklist;
use Modules\Settings\Support\SettingsFeatureFlags;
use Modules\Settings\Support\StoreEmailCheck;
use Modules\Support\Events\StoredSecretsReencrypted;
use Modules\Support\Providers\ModuleServiceProvider;
use Modules\Support\Security\EncryptedColumn;

final class SettingsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Settings';
    }

    protected function registerModule(): void
    {
        $this->app->bind(StoreSettings::class, DatabaseStoreSettings::class);
        $this->app->bind(FeatureFlags::class, SettingsFeatureFlags::class);
        $this->app->singleton(OnboardingChecks::class, LaunchChecklist::class);
    }

    protected function bootModule(): void
    {
        $this->app->make(OnboardingChecks::class)->register(StoreEmailCheck::class);
        // Theme, store name, flags… are part of every page: purge the storefront's config tag.
        Setting::saved(fn () => $this->app->make(StorefrontCache::class)->invalidate(['config']));
        // The settings cache holds ciphertext; after a key rotation it must not outlive the old key.
        Event::listen(StoredSecretsReencrypted::class, fn () => DatabaseStoreSettings::flush());
    }

    protected function defaultSeeders(): array
    {
        return [DefaultSettingsSeeder::class];
    }

    protected function encryptedColumns(): array
    {
        return [EncryptedColumn::make('settings', 'value')->json()->where('is_encrypted', true)];
    }
}
