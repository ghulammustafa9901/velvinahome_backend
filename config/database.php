<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Database connections (spec §6.3, docs/architecture/database-roles.md)
|--------------------------------------------------------------------------
|
| PostgreSQL only, one database per installation. Each connection maps to exactly one
| PostgreSQL role, because the privilege model — not application discipline — is what keeps
| ledgers append-only and the schema out of the application's reach:
|
|   pgsql           store_app        DML only; serves every request, job and command
|   pgsql_migrator  store_migrator   owns the schema; used by `app:migrate` during deploys
|
*/

$pgsql = static fn (array $overrides): array => array_merge([
    'driver' => 'pgsql',
    'url' => null,
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'store'),
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => env('DB_SSLMODE', 'prefer'),
], $overrides);

// Application traffic goes through PgBouncer in transaction mode when DB_POOLER_HOST is set.
// Without it every request starts a fresh PostgreSQL backend: a connect plus cold catalog
// caches on every query (docs/performance/load-test.md). The migration role always connects
// directly: transactional DDL needs a session.
$pooler = env('DB_POOLER_HOST') ? ['host' => env('DB_POOLER_HOST'), 'port' => env('DB_POOLER_PORT', '6432')] : [];

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'pooler' => $pooler,

    'connections' => [

        'pgsql' => $pgsql([
            ...$pooler,
            'username' => env('DB_USERNAME', 'store_app'),
            'password' => env('DB_PASSWORD', ''),
        ]),

        'pgsql_migrator' => $pgsql([
            'username' => env('DB_MIGRATOR_USERNAME', 'store_migrator'),
            'password' => env('DB_MIGRATOR_PASSWORD', ''),
        ]),

        // Only for the Support module's own test fixtures, which exercise pure model
        // behaviour without a database server.
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => null,
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis
    |--------------------------------------------------------------------------
    |
    | predis, not phpredis: it is pure PHP and so behaves the same on every developer
    | machine, including Windows (ADR-0009). Separate logical databases keep queues, cache
    | and sessions from ever sharing a keyspace; REDIS_PREFIX keeps two installations that
    | share a Redis server apart.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'predis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'store')).':'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

        'session' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_SESSION_DB', '2'),
        ],

    ],

];
