<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Support\Modules\ModuleRegistry;

/*
| database-roles.md — the privilege model, proven against the real database:
|  1. the application role cannot run DDL
|  2. every append-only table rejects UPDATE/DELETE/TRUNCATE under the application role
|  3. the revoke list covers every table declared append-only in code
|  4. no role the application uses has SUPERUSER/CREATEDB/CREATEROLE
*/

it('denies DDL to the application role', function (): void {
    expect(fn () => DB::connection('pgsql')->statement('create table ddl_probe (id int)'))
        ->toThrow(QueryException::class, 'permission denied');
});

it('gives neither application nor migration role superuser, createdb or createrole', function (): void {
    $roles = [config('database.connections.pgsql.username'), config('database.connections.pgsql_migrator.username')];

    $privileged = DB::table('pg_roles')
        ->whereIn('rolname', $roles)
        ->where(fn ($query) => $query->where('rolsuper', true)->orWhere('rolcreatedb', true)->orWhere('rolcreaterole', true))
        ->pluck('rolname')
        ->all();

    expect(DB::table('pg_roles')->whereIn('rolname', $roles)->count())->toBe(2)
        ->and($privileged)->toBe([]);
});

it('rejects UPDATE, DELETE and TRUNCATE on every append-only table under the application role', function (): void {
    $connection = DB::connection('pgsql');
    $role = (string) config('database.connections.pgsql.username');
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

it('sends application traffic through the pooler and migrations around it', function (): void {
    putenv('DB_POOLER_HOST=pgbouncer.internal');
    $_ENV['DB_POOLER_HOST'] = $_SERVER['DB_POOLER_HOST'] = 'pgbouncer.internal';

    try {
        $config = require base_path('config/database.php');
    } finally {
        putenv('DB_POOLER_HOST');
        unset($_ENV['DB_POOLER_HOST'], $_SERVER['DB_POOLER_HOST']);
    }

    // Transaction pooling forbids session state, which transactional DDL needs.
    expect($config['connections']['pgsql']['host'])->toBe('pgbouncer.internal')
        ->and($config['connections']['pgsql_migrator']['host'])->not->toBe('pgbouncer.internal');
});
