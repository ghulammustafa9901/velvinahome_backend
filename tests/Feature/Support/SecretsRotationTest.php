<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payments\Models\PaymentMethod;
use Modules\Settings\Contracts\TenantSettings;
use Modules\Settings\Models\Setting;
use Modules\Support\Contracts\ReencryptsStoredSecrets;
use Modules\Support\Modules\ModuleRegistry;
use Modules\Support\Security\EncryptedColumn;
use Tests\Support\ModuleInspector;

/*
| APP_KEY rotation (docs/runbooks/rotate-secrets.md): with the new key current and the old one
| in APP_PREVIOUS_KEYS, `secrets:reencrypt` moves every stored secret to the new key, so the
| old key can be retired without losing a credential or a 2FA secret.
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
    $declared = array_map(
        fn (EncryptedColumn $column): string => $column->key(),
        app(ModuleRegistry::class)->encryptedColumns(EncryptedColumn::TENANT),
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
                    $key = $model->getTable().'.'.$attribute;
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
    $settingKey = 'rotation_'.Str::lower(Str::random(6));

    // Secrets under the old key, and the settings cache warmed with that ciphertext.
    PaymentMethod::query()->where('code', 'cod')->firstOrFail()->forceFill(['credentials' => ['token' => 'cod-secret']])->save();
    Setting::query()->create(['group' => 'test', 'key' => $settingKey, 'value' => Crypt::encryptString('setting-secret'), 'is_encrypted' => true]);
    expect(app(TenantSettings::class)->string("test.{$settingKey}"))->toBe('setting-secret');

    try {
        useAppKey($new, [$old]);

        $this->artisan('secrets:reencrypt')->expectsOutputToContain('can be retired')->assertSuccessful();

        // Old key retired: everything still opens.
        useAppKey($new, []);
        $fresh = onlyKey($new);
        $raw = (string) DB::table('payment_methods')->where('code', 'cod')->value('credentials');

        expect(json_decode($fresh->decryptString($raw), true))->toBe(['token' => 'cod-secret'])
            ->and(app(TenantSettings::class)->string("test.{$settingKey}"))->toBe('setting-secret');

        // A second run has nothing left to move.
        useAppKey($new, [$old]);
        $second = app(ReencryptsStoredSecrets::class)->handle(EncryptedColumn::TENANT, (string) config('database.default'));
        expect(array_sum(array_column($second, 'reencrypted')))->toBe(0);
    } finally {
        // Put the shared test database back under the suite's key.
        useAppKey($old, [$new]);
        $this->artisan('secrets:reencrypt')->assertSuccessful();
        Setting::query()->where('key', $settingKey)->delete();
        PaymentMethod::query()->where('code', 'cod')->firstOrFail()->forceFill(['credentials' => null])->save();
    }
});

it('reports values no configured key can read, and keeps the old key then', function (): void {
    $foreign = onlyKey('base64:'.base64_encode(random_bytes(32)))->encryptString(json_encode(['token' => 'x'], JSON_THROW_ON_ERROR));

    DB::table('payment_methods')->where('code', 'cod')->update(['credentials' => $foreign]);

    try {
        $this->artisan('secrets:reencrypt')
            ->expectsOutputToContain('payment_methods.credentials')
            ->expectsOutputToContain('Keep the old key')
            ->assertFailed();
    } finally {
        DB::table('payment_methods')->where('code', 'cod')->update(['credentials' => null]);
    }
});
