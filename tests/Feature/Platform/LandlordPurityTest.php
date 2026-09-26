<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Platform\Models\TenantDatabase;
use Tests\Support\TestPlatform;

/*
| Spec §4.1: the landlord database never stores a tenant's customer, order, product or payment
| data; tenant DB credentials in it are unreadable without APP_KEY.
*/

it('contains no table that could hold tenant commerce data', function (): void {
    $tables = DB::connection('landlord')->table('pg_tables')
        ->where('schemaname', 'public')
        ->pluck('tablename')
        ->all();

    $forbidden = ['customer', 'order', 'product', 'variant', 'payment', 'cart', 'stock', 'inventory', 'address', 'shipment', 'review', 'wishlist'];

    $violations = array_values(array_filter(
        $tables,
        fn (string $table): bool => collect($forbidden)->contains(fn (string $word): bool => str_contains($table, $word)),
    ));

    expect($violations)->toBe([]);
});

it('stores tenant database passwords encrypted', function (): void {
    $credentials = TestPlatform::brand(TestPlatform::BRAND_B)->databaseCredentials;
    $raw = DB::connection('landlord')->table('tenant_databases')->where('id', $credentials?->id)->first();

    expect($raw->app_password)->not->toBe($credentials?->app_password)
        ->and($raw->migration_password)->not->toBe($credentials?->migration_password)
        ->and(decrypt($raw->app_password, false))->toBe($credentials?->app_password);
});

it('cannot decrypt tenant credentials with a different APP_KEY', function (): void {
    $raw = DB::connection('landlord')->table('tenant_databases')
        ->where('tenant_id', TestPlatform::brand(TestPlatform::BRAND_B)->id)
        ->value('app_password');

    $otherKey = new Illuminate\Encryption\Encrypter(random_bytes(32), 'AES-256-CBC');

    expect(fn () => $otherKey->decrypt($raw, false))->toThrow(Illuminate\Contracts\Encryption\DecryptException::class);
});

it('never serialises credentials into arrays or JSON', function (): void {
    $array = TenantDatabase::query()->where('tenant_id', TestPlatform::brand(TestPlatform::BRAND_B)->id)->firstOrFail()->toArray();

    expect($array)->not->toHaveKey('app_password')
        ->and($array)->not->toHaveKey('migration_password');
});
