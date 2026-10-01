<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Platform identity (spec §16.1 — final name and domain still open)
    |--------------------------------------------------------------------------
    */

    'name' => env('PLATFORM_NAME', 'Commerce Platform'),

    // The landlord panel is served on this host only. Every other host must resolve to a
    // tenant domain or 404.
    'host' => env('PLATFORM_HOST', 'platform.localhost'),

    /*
    |--------------------------------------------------------------------------
    | Topology (spec §4.3)
    |--------------------------------------------------------------------------
    |
    | shared:    one installation, many tenants, resolved per request by hostname.
    | dedicated: this installation serves exactly one tenant, pinned by id. The same
    |            bootstrappers run, so tenant-aware behaviour is identical by construction.
    |
    */

    'topology' => env('TENANCY_TOPOLOGY', 'shared'),

    'pinned_tenant' => env('TENANCY_PINNED_TENANT'),

    /*
    |--------------------------------------------------------------------------
    | Tenant database provisioning (docs/architecture/database-roles.md)
    |--------------------------------------------------------------------------
    */

    'provisioning' => [
        'database_prefix' => env('TENANT_DB_NAME_PREFIX', 'tenant_'),
        'role_prefix' => env('TENANT_DB_ROLE_PREFIX', 'tenant_'),
        'backup_role' => env('DB_BACKUP_ROLE', 'backup_reader'),
        'pg_dump_binary' => env('PG_DUMP_BINARY', 'pg_dump'),
        'pg_restore_binary' => env('PG_RESTORE_BINARY', 'pg_restore'),
        // How many nightly exports per brand stay on disk (tenants:backup prunes the rest).
        'export_retention' => (int) env('TENANT_EXPORT_RETENTION', 14),
        'export_disk' => env('TENANT_EXPORT_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Staff security (spec §6.2)
    |--------------------------------------------------------------------------
    */

    'staff' => [
        // Mandatory everywhere; the flag exists only so unrelated tests need not enrol 2FA.
        'two_factor_required' => (bool) env('STAFF_2FA_REQUIRED', true),
        'max_login_attempts' => (int) env('STAFF_MAX_LOGIN_ATTEMPTS', 5),
        'lockout_seconds' => (int) env('STAFF_LOCKOUT_SECONDS', 900),
    ],

    'passwords' => [
        // Have I Been Pwned k-anonymity check. Disabled only in the test suite.
        'check_breached' => (bool) env('PASSWORD_BREACH_CHECK', true),
    ],

    'impersonation' => [
        'ttl_minutes' => (int) env('IMPERSONATION_TTL_MINUTES', 30),
    ],

    'brand_switch' => [
        'ttl_seconds' => (int) env('BRAND_SWITCH_TTL_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storefront BFF service authentication (ADR-0002, ADR-0003)
    |--------------------------------------------------------------------------
    */

    'service_auth' => [
        'secret' => env('SERVICE_AUTH_SECRET'),
        'max_age_seconds' => (int) env('SERVICE_AUTH_MAX_AGE_SECONDS', 60),
    ],

    // Backend-triggered ISR purges (spec §6.13). The URL defaults to <storefront>/api/revalidate;
    // set it for local development, where the storefront runs on its own port.
    'storefront' => [
        'revalidate_secret' => env('STOREFRONT_REVALIDATE_SECRET'),
        'revalidate_url' => env('STOREFRONT_REVALIDATE_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Imports (WooCommerce, CSV)
    |--------------------------------------------------------------------------
    |
    | Outbound requests to a store URL typed by staff may only reach public addresses (SSRF
    | guard). Local development and tests talk to stores on private or fake hosts.
    |
    */

    'imports' => [
        'allow_private_hosts' => (bool) env('IMPORTS_ALLOW_PRIVATE_HOSTS', in_array(env('APP_ENV'), ['local', 'testing'], true)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Release metadata
    |--------------------------------------------------------------------------
    */

    'release' => env('APP_RELEASE', 'dev'),

    /*
    |--------------------------------------------------------------------------
    | Monitoring (spec §9; PostHog chosen by the owner, ADR-0019)
    |--------------------------------------------------------------------------
    |
    | The project key is set per environment, never committed. Empty = reporting off.
    |
    */

    'monitoring' => [
        'posthog' => [
            'key' => env('POSTHOG_PROJECT_KEY', ''),
            'host' => env('POSTHOG_HOST', 'https://eu.i.posthog.com'),
        ],
        // Alert when queued work piles up or fails (health endpoint, failed-job alerts).
        'queue_backlog_alert' => (int) env('MONITORING_QUEUE_BACKLOG_ALERT', 1000),
        'alert_email' => env('MONITORING_ALERT_EMAIL'),
        // Unlocks the per-component detail of /health (uptime monitors send it).
        'health_token' => env('HEALTH_CHECK_TOKEN', ''),
    ],

];
