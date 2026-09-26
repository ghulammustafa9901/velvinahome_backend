<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Tenancy\TenantDatabaseConfig;
use Modules\Support\Modules\ModuleRegistry;
use Tests\Support\TestPlatform;

/*
| database-roles.md §8 — the privilege model is what makes isolation real:
|  1. a tenant app role cannot CONNECT to another tenant's database
|  2. a tenant app role cannot run DDL
|  3. every append-only table rejects UPDATE/DELETE under the app role
|  4. the revoke list covers every table declared append-only in code
|  5. landlord_app cannot reach tenant databases; tenant roles cannot reach the landlord
|  6. no runtime role has SUPERUSER/CREATEDB/CREATEROLE
*/

/**
 * @param  array<string, mixed>  $overrides
 */
function connectAs(string $name, array $config, array $overrides = []): \Illuminate\Database\Connection
{
    config(["database.connections.{$name}" => array_merge($config, $overrides)]);
    DB::purge($name);

    return DB::connection($name);
}

function appConnection(string $slug, array $overrides = []): \Illuminate\Database\Connection
{
    /** @var TenantDatabaseConfig $database */
    $database = TestPlatform::brand($slug)->database();

    return connectAs('probe_app_'.str_replace('-', '_', $slug), $database->connection(), $overrides);
}

it('stops a tenant app role from connecting to another tenant database', function (string $target): void {
    $foreignDatabase = TestPlatform::brand($target)->database()->getName();

    expect(fn () => appConnection(TestPlatform::BRAND_B, ['database' => $foreignDatabase])->select('select 1'))
        ->toThrow(QueryException::class, 'permission denied');
})->with([
    'a brand of another company' => TestPlatform::BRAND_A,
    'a sibling brand of the same company' => TestPlatform::BRAND_C,
]);

it('stops a tenant app role from connecting to the landlord database', function (): void {
    expect(fn () => appConnection(TestPlatform::BRAND_B, ['database' => config('database.connections.landlord.database')])->select('select 1'))
        ->toThrow(QueryException::class, 'permission denied');
});

it('stops the landlord app role from connecting to a tenant database', function (): void {
    $connection = connectAs('probe_landlord', config('database.connections.landlord'), [
        'database' => TestPlatform::brand(TestPlatform::BRAND_B)->database()->getName(),
    ]);

    expect(fn () => $connection->select('select 1'))->toThrow(QueryException::class, 'permission denied');
});

it('denies DDL to the tenant app role', function (): void {
    expect(fn () => appConnection(TestPlatform::BRAND_B)->statement('create table ddl_probe (id int)'))
        ->toThrow(QueryException::class, 'permission denied');
});

it('gives no runtime role superuser, createdb or createrole', function (): void {
    $roles = [
        config('database.connections.landlord.username'),
        TestPlatform::brand(TestPlatform::BRAND_B)->database()->getUsername(),
        TestPlatform::brand(TestPlatform::BRAND_B)->databaseCredentials?->migration_username,
    ];

    $privileged = DB::connection('landlord')->table('pg_roles')
        ->whereIn('rolname', $roles)
        ->where(fn ($query) => $query->where('rolsuper', true)->orWhere('rolcreatedb', true)->orWhere('rolcreaterole', true))
        ->pluck('rolname')
        ->all();

    expect($privileged)->toBe([]);
});

it('rejects UPDATE, DELETE and TRUNCATE on every append-only table under the app role', function (): void {
    $connection = appConnection(TestPlatform::BRAND_B);
    $role = TestPlatform::brand(TestPlatform::BRAND_B)->database()->getUsername();
    $tables = app(ModuleRegistry::class)->appendOnlyTables();

    expect($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        $privileges = $connection->selectOne(
            'select has_table_privilege(?, ?, \'INSERT\') as can_insert,
                    has_table_privilege(?, ?, \'SELECT\') as can_select,
                    has_table_privilege(?, ?, \'UPDATE\') as can_update,
                    has_table_privilege(?, ?, \'DELETE\') as can_delete,
                    has_table_privilege(?, ?, \'TRUNCATE\') as can_truncate',
            [$role, $table, $role, $table, $role, $table, $role, $table, $role, $table],
        );

        expect([$table => [$privileges->can_insert, $privileges->can_select, $privileges->can_update, $privileges->can_delete, $privileges->can_truncate]])
            ->toBe([$table => [true, true, false, false, false]]);
    }

    expect(fn () => $connection->table('login_audits')->delete())->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => $connection->table('activity_log')->update(['description' => 'rewritten']))->toThrow(QueryException::class, 'permission denied');
});

it('locks down the landlord audit tables for the landlord app role', function (): void {
    $landlord = DB::connection('landlord');

    expect(fn () => $landlord->table('platform_activity_log')->delete())->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => $landlord->table('platform_login_audits')->update(['successful' => true]))->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => $landlord->table('impersonation_sessions')->delete())->toThrow(QueryException::class, 'permission denied');
});
