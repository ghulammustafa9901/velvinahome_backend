<?php

declare(strict_types=1);

namespace Modules\Settings\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Settings\Data\StoreSettingsData;
use Modules\Settings\Support\SettingsFeatureFlags;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Saves the admin's Store settings page in one transaction: store identity, theme, tax display,
 * SEO, mail sender and feature flags. Requires settings.manage.
 */
final class SaveStoreSettings implements Action
{
    use ChecksPermission;

    public function __construct(private readonly UpdateSetting $update) {}

    public function handle(StoreSettingsData $data, ?Authorizable $actor = null): void
    {
        $this->authorizePermission($actor, 'settings.manage');

        $tokens = $this->tokens($data->themeTokens ?? []);
        $features = array_intersect_key(array_map(fn (mixed $on): bool => (bool) $on, $data->features ?? []), SettingsFeatureFlags::DEFAULTS);

        $values = [
            'store' => [
                'name' => $data->name,
                'url' => rtrim($data->url, '/'),
                'timezone' => $data->timezone,
                'locale' => $data->locale,
                'country' => $data->country,
                'contact_email' => $data->contactEmail,
                'contact_phone' => $data->contactPhone,
            ],
            'theme' => ['id' => $data->themeId, 'tokens' => (object) $tokens],
            'tax' => ['mode' => $data->taxMode],
            'seo' => ['robots_allow_indexing' => $data->allowIndexing, 'title_suffix' => $data->titleSuffix],
            'mail' => ['from_name' => $data->mailFromName, 'from_address' => $data->mailFromAddress],
            'features' => $features,
        ];

        DB::transaction(function () use ($values): void {
            foreach ($values as $group => $settings) {
                foreach ($settings as $key => $value) {
                    $this->update->handle($group, $key, $value);
                }
            }
        });
    }

    /**
     * Token names are CSS custom-property names; values may not break out of a declaration.
     * The storefront's theme engine validates them again before rendering.
     *
     * @param  array<array-key, mixed>  $tokens
     * @return array<string, string>
     */
    private function tokens(array $tokens): array
    {
        $clean = [];

        foreach ($tokens as $name => $value) {
            $name = (string) $name;
            $value = trim((string) $value);

            if (preg_match('/^[a-z][a-z0-9-]{0,40}$/', $name) !== 1 || $value === '' || mb_strlen($value) > 200 || preg_match('/[<>{};]/', $value) === 1) {
                throw ValidationException::withMessages(['themeTokens' => __('Theme token ":name" is not valid.', ['name' => $name])]);
            }

            $clean[$name] = $value;
        }

        return $clean;
    }
}
