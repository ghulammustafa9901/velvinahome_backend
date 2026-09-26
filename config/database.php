<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Database connections (spec §4, §6.3, docs/architecture/database-roles.md)
|--------------------------------------------------------------------------
|
| PostgreSQL only. Every connection here maps to exactly one PostgreSQL role, because the
| privilege model — not application discipline — is what makes tenant isolation real:
|
|   landlord           landlord_app          DML on the landlord DB, the central default
|   landlord_migrator  landlord_migrator     owns the landlord schema; deploys only
|   provisioner        platform_provisioner  CREATEDB/CREATEROLE, never a superuser
|   tenant_template    (none)                host/port/options every tenant connection copies
|   tenant             tenant_<slug>_app     created at runtime once a tenant is resolved
|   tenant_migrator    tenant_<slug>_migrator created at runtime by tenant migrations only
|
| `tenant` and `tenant_migrator` are never declared here: they are built from the encrypted
| credentials in the landlord `tenant_databases` table, so a tenant connection cannot exist
| before a tenant has been resolved.
|
*/

$pgsql = static fn (array $overrides): array => array_merge([
    'driver' => 'pgsql',
    'url' => null,
    'host' => env('DB_LANDLORD_HOST', '127.0.0.1'),
    'port' => env('DB_LANDLORD_PORT', '5432'),
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => env('DB_LANDLORD_SSLMODE', 'prefer'),
], $overrides);

return [

    'default' => env('DB_CONNECTION', 'landlord'),

    'connections' => [

        'landlord' => $pgsql([
            'database' => env('DB_LANDLORD_DATABASE', 'platform_landlord'),
            'username' => env('DB_LANDLORD_USERNAME', 'landlord_app'),
            'password' => env('DB_LANDLORD_PASSWORD', ''),
        ]),

        'landlord_migrator' => $pgsql([
            'database' => env('DB_LANDLORD_DATABASE', 'platform_landlord'),
            'username' => env('DB_LANDLORD_MIGRATOR_USERNAME', 'landlord_migrator'),
            'password' => env('DB_LANDLORD_MIGRATOR_PASSWORD', ''),
        ]),

        // Connects to the maintenance database only; tenant databases are created from here.
        'provisioner' => $pgsql([
            'host' => env('DB_PROVISIONER_HOST', env('DB_LANDLORD_HOST', '127.0.0.1')),
            'port' => env('DB_PROVISIONER_PORT', env('DB_LANDLORD_PORT', '5432')),
            'database' => env('DB_PROVISIONER_DATABASE', 'postgres'),
            'username' => env('DB_PROVISIONER_USERNAME', 'platform_provisioner'),
            'password' => env('DB_PROVISIONER_PASSWORD', ''),
        ]),

        // Never connected to directly. Tenant connections are this plus the tenant's own
        // database name and role credentials.
        'tenant_template' => $pgsql([
            'host' => env('DB_TENANT_HOST', env('DB_LANDLORD_HOST', '127.0.0.1')),
            'port' => env('DB_TENANT_PORT', env('DB_LANDLORD_PORT', '5432')),
            'database' => null,
            'username' => null,
            'password' => null,
            'sslmode' => env('DB_TENANT_SSLMODE', env('DB_LANDLORD_SSLMODE', 'prefer')),
        ]),

        // Only for the Support module's own test fixtures, which exercise pure model
        // behaviour without any tenancy.
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
    | and sessions from ever sharing a keyspace.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'predis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'platform')).':'),
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
