<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Contracts\SearchIndex;
use Modules\Identity\Contracts\StaffDirectory;
use Modules\Settings\Actions\UpdateSetting;
use Modules\Settings\Contracts\OnboardingChecks;
use Modules\Settings\Contracts\StoreSettings;
use Modules\Support\Enums\Currency;

/**
 * Sets this installation up as one client's store (docs/runbooks/install-store.md): schema and
 * module defaults, the store's identity in settings, the owner account, the search collection,
 * then the launch checklist. Idempotent — run it again to fill in what is missing; options
 * left out change nothing. Composes several modules, so it lives in the application layer.
 */
final class InstallStoreCommand extends Command
{
    protected $signature = 'store:install
        {--name= : Store name}
        {--url= : Public storefront URL, e.g. https://velvinahome.com}
        {--currency= : ISO currency code; fixed once the catalog has prices}
        {--timezone= : e.g. Asia/Karachi}
        {--locale= : e.g. en}
        {--country= : ISO country code, e.g. PK}
        {--theme= : Storefront theme id, e.g. default or velvina-home}
        {--owner-email= : Create (or promote) the owner account}
        {--owner-name=Store owner : The owner\'s name, for a new account}
        {--skip-migrate : Do not run app:migrate first}';

    protected $description = 'Set this installation up as a store: schema, identity, owner account, search, checklist';

    public function handle(UpdateSetting $update, StoreSettings $settings, StaffDirectory $staff, SearchIndex $search, OnboardingChecks $checklist): int
    {
        if (! $this->option('skip-migrate') && $this->call('app:migrate') !== self::SUCCESS) {
            return self::FAILURE;
        }

        $values = $this->identity($settings);
        $email = $this->option('owner-email');

        // Every option is checked before anything is written.
        if (is_string($email) && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error('--owner-email is not an email address.');

            return self::FAILURE;
        }

        if ($values === null) {
            return self::FAILURE;
        }

        foreach ($values as $key => $value) {
            [$group, $name] = explode('.', $key, 2);
            $update->handle($group, $name, $value);
            $this->components->twoColumnDetail($key, (string) $value);
        }

        if (is_string($email) && $email !== '') {
            $owner = $staff->ensureOwner((string) $this->option('owner-name'), $email);

            $owner->created
                ? $this->components->warn("Owner {$owner->email} created. One-time password (shown once, change it at first sign-in): {$owner->temporaryPassword}")
                : $this->components->info("Owner {$owner->email} already exists; the Owner role is ensured.");
        }

        $search->ensureCollections();
        $this->warnAboutSharedInfrastructure();

        $items = $checklist->items();
        $this->newLine();
        $this->components->info(sprintf('Launch checklist: %d of %d done', count(array_filter($items, fn (array $item): bool => $item['done'])), count($items)));
        foreach ($items as $item) {
            $this->components->twoColumnDetail($item['label'], $item['done'] ? '<fg=green>done</>' : '<fg=yellow>'.$item['hint'].'</>');
        }

        return self::SUCCESS;
    }

    /**
     * The settings to write, validated; null after reporting an invalid option.
     *
     * @return array<string, string>|null
     */
    private function identity(StoreSettings $settings): ?array
    {
        $values = array_filter([
            'store.name' => $this->option('name'),
            'store.url' => is_string($this->option('url')) ? rtrim($this->option('url'), '/') : null,
            'store.timezone' => $this->option('timezone'),
            'store.locale' => $this->option('locale'),
            'store.country' => is_string($this->option('country')) ? strtoupper($this->option('country')) : null,
            'theme.id' => $this->option('theme'),
            'store.currency' => is_string($this->option('currency')) ? strtoupper($this->option('currency')) : null,
        ], fn ($value): bool => is_string($value) && $value !== '');

        $url = $values['store.url'] ?? null;
        if ($url !== null && (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || ! is_string(parse_url($url, PHP_URL_HOST)))) {
            return $this->invalid('--url must be an absolute http(s) URL.');
        }

        if (isset($values['store.timezone']) && ! in_array($values['store.timezone'], timezone_identifiers_list(), true)) {
            return $this->invalid('--timezone is not a known timezone.');
        }

        if (isset($values['theme.id']) && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $values['theme.id']) !== 1) {
            return $this->invalid('--theme must be a theme id such as default or velvina-home.');
        }

        if (isset($values['store.country']) && preg_match('/^[A-Z]{2}$/', $values['store.country']) !== 1) {
            return $this->invalid('--country must be a two-letter country code.');
        }

        if (isset($values['store.currency'])) {
            if (Currency::tryFrom($values['store.currency']) === null) {
                return $this->invalid('--currency is not a supported currency.');
            }

            // Prices and orders carry their currency; changing it under them would corrupt totals.
            if ($values['store.currency'] !== $settings->string('store.currency', 'PKR') && DB::table('product_variants')->exists()) {
                return $this->invalid('The currency is fixed once the catalog has prices.');
            }
        }

        /** @var array<string, string> $values */
        return $values;
    }

    private function invalid(string $message): null
    {
        $this->components->error($message);

        return null;
    }

    /**
     * Two installations on one Typesense server must not share a collection (REDIS_PREFIX and
     * OBJECT_STORAGE_PREFIX play the same part for Redis and the bucket; install-store.md).
     */
    private function warnAboutSharedInfrastructure(): void
    {
        if (app()->isProduction() && (string) config('scout.prefix') === '') {
            $this->components->warn('SCOUT_PREFIX is empty: set it if this Typesense server is shared with another installation.');
        }
    }
}
