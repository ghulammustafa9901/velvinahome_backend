<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payments\Models\PaymentMethod;
use Modules\Platform\Actions\MigrateTenants;
use Modules\Platform\Actions\ReencryptAllSecrets;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantDatabase;
use Modules\Settings\Contracts\TenantSettings;
use Modules\Settings\Models\Setting;
use Modules\Support\Modules\ModuleRegistry;
use Modules\Support\Security\EncryptedColumn;
use Tests\Support\ModuleInspector;
use Tests\Support\TestPlatform;

/*
| APP_KEY rotation (docs/runbooks/rotate-secrets.md): with the new key current and the old one
| in APP_PREVIOUS_KEYS, `secrets:reencrypt` moves every stored secret — landlord and tenants —
| to the new key, so the old key can be retired without losing a credential or a 2FA secret.
*/

function useAppKey(string $key, array $previous): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function onlyKey(string $key): Encrypter
{
    return new Encrypter(base64_decode(Str::after($key, 'base64:'), true), (string) config('app.cipher'));
}

it('declares every encrypted model cast, so a rotation cannot miss one', function (): void {
    $registry = app(ModuleRegistry::class);
    $declared = array_map(
        fn (EncryptedColumn $column): string => $column->scope.':'.$column->key(),
        [...$registry->encryptedColumns(EncryptedColumn::CENTRAL), ...$registry->encryptedColumns(EncryptedColumn::TENANT)],
    );
    $missing = [];

    foreach (ModuleInspector::modules() as $module) {
        foreach (ModuleInspector::filesInModuleDirectory($module, 'Models') as $file) {
            $class = ModuleInspector::classNameFor($module, $file);

            if (! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $model = new $class;

            foreach ($model->getCasts() as $attribute => $cast) {
                if (is_string($cast) && str_starts_with($cast, 'encrypted')) {
                    $key = ($model->getConnectionName() === 'landlord' ? EncryptedColumn::CENTRAL : EncryptedColumn::TENANT).':'.$model->getTable().'.'.$attribute;
                    in_array($key, $declared, true) || $missing[] = "{$class}::{$attribute} ({$key})";
                }
            }
        }
    }

    expect($missing)->toBe([], 'Declare these in the module provider\'s encryptedColumns().');
});

it('moves every stored secret to the new key and can retire the old one', function (): void {
    $old = (string) config('app.key');
    $new = 'base64:'.base64_encode(random_bytes(32));
    $tenantId = TestPlatform::brand(TestPlatform::BRAND_A)->id;
    // Loaded fresh after every key change: a tenant model loaded earlier still carries the
    // database password as it was encrypted then (as a long-running worker would).
    $brand = fn (): Tenant => Tenant::query()->whereKey($tenantId)->firstOrFail();
    $settingKey = 'rotation_'.Str::lower(Str::random(6));

    // Secrets under the old key, and the settings cache warmed with that ciphertext.
    $brand()->run(function () use ($settingKey): void {
        PaymentMethod::query()->where('code', 'cod')->firstOrFail()->forceFill(['credentials' => ['token' => 'cod-secret']])->save();
        Setting::query()->create(['group' => 'test', 'key' => $settingKey, 'value' => Crypt::encryptString('setting-secret'), 'is_encrypted' => true]);
        expect(app(TenantSettings::class)->string("test.{$settingKey}"))->toBe('setting-secret');
    });

    try {
        useAppKey($new, [$old]);

        $this->artisan('secrets:reencrypt')->expectsOutputToContain('can be retired')->assertSuccessful();

        $fresh = onlyKey($new);
        $landlordPassword = (string) DB::connection('landlord')->table('tenant_databases')->where('tenant_id', $tenantId)->value('app_password');
        expect($fresh->decryptString($landlordPassword))->not->toBe('');

        // Old key retired: everything still opens, including the tenant connection itself.
        useAppKey($new, []);

        $brand()->run(function () use ($settingKey, $fresh): void {
            $raw = (string) DB::connection('tenant')->table('payment_methods')->where('code', 'cod')->value('credentials');
            expect(json_decode($fresh->decryptString($raw), true))->toBe(['token' => 'cod-secret'])
                ->and(app(TenantSettings::class)->string("test.{$settingKey}"))->toBe('setting-secret');
        });

        expect(TenantDatabase::query()->where('tenant_id', $tenantId)->firstOrFail()->app_password)->not->toBe('');

        // A second run has nothing left to move.
        useAppKey($new, [$old]);
        $second = app(ReencryptAllSecrets::class)->handle();
        expect(array_filter($second, 'is_string'))->toBe([])
            ->and(array_sum(array_map(fn (array $columns): int => array_sum(array_column($columns, 'reencrypted')), $second)))->toBe(0);
    } finally {
        // Put the shared test databases back under the suite's key.
        tenancy()->end();
        useAppKey($old, [$new]);
        $this->artisan('secrets:reencrypt')->assertSuccessful();
        $brand()->run(function () use ($settingKey): void {
            Setting::query()->where('key', $settingKey)->delete();
            PaymentMethod::query()->where('code', 'cod')->firstOrFail()->forceFill(['credentials' => null])->save();
        });
    }
});

it('rotates a brand\'s database role passwords; the old ones stop working', function (): void {
    $brand = TestPlatform::brand(TestPlatform::BRAND_C);
    $before = $brand->databaseCredentials()->firstOrFail();
    $oldApp = $before->app_password;
    $oldMigration = $before->migration_password;
    $dsn = "pgsql:host={$before->host};port={$before->port};dbname={$before->database}";

    $this->artisan('tenant:rotate-db-passwords', ['slug' => TestPlatform::BRAND_C])->assertSuccessful();
    // The suite's cached fixture model still holds the old credentials relation.
    $brand->unsetRelation('databaseCredentials');

    $after = $brand->databaseCredentials()->firstOrFail();

    expect($after->app_password)->not->toBe($oldApp)
        ->and($after->migration_password)->not->toBe($oldMigration)
        ->and(fn () => new PDO($dsn, $before->app_username, $oldApp))->toThrow(PDOException::class)
        ->and(fn () => new PDO($dsn, $before->migration_username, $oldMigration))->toThrow(PDOException::class)
        ->and((new PDO($dsn, $after->app_username, $after->app_password))->query('select 1')->fetchColumn())->toBe(1);

    DB::purge('tenant');
    $this->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_C).'/api/v1/storefront/config')->assertOk();
    expect(app(MigrateTenants::class)->handle([TestPlatform::BRAND_C])[0]->status)->toBe('succeeded');
});

it('reports values no configured key can read, and keeps the old key then', function (): void {
    $brand = TestPlatform::brand(TestPlatform::BRAND_B);
    $foreign = onlyKey('base64:'.base64_encode(random_bytes(32)))->encryptString(json_encode(['token' => 'x'], JSON_THROW_ON_ERROR));

    $brand->run(fn () => DB::connection('tenant')->table('payment_methods')->where('code', 'cod')->update(['credentials' => $foreign]));

    try {
        $this->artisan('secrets:reencrypt')
            ->expectsOutputToContain('payment_methods.credentials: 1 value(s) no configured key can decrypt')
            ->assertFailed();
    } finally {
        $brand->run(fn () => DB::connection('tenant')->table('payment_methods')->where('code', 'cod')->update(['credentials' => null]));
    }
});
