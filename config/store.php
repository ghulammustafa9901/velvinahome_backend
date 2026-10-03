<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Store application settings that belong to the installation, not the admin
    |--------------------------------------------------------------------------
    |
    | Store identity (name, URL, logo, currency, theme, flags) lives in the admin's settings;
    | this file holds only what is deployment configuration or a secret, from `.env`.
    |
    */

    // Hosts (ADR-0002). Empty = answer on any host. In production the admin and the API live on
    // their own subdomains so each gets its own WAF rules.
    'admin_domain' => env('ADMIN_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Backups (spec §9)
    |--------------------------------------------------------------------------
    */

    'backup' => [
        'role' => env('DB_BACKUP_ROLE', 'backup_reader'),
        'pg_dump_binary' => env('PG_DUMP_BINARY', 'pg_dump'),
        'pg_restore_binary' => env('PG_RESTORE_BINARY', 'pg_restore'),
        // How many nightly dumps stay on disk (older ones are pruned).
        'retention' => (int) env('BACKUP_RETENTION', 14),
        'path' => env('BACKUP_PATH') ?: storage_path('app/backups'),
        // The restore drill's scratch database, created once by bootstrap-database.sql.
        'drill_database' => env('BACKUP_DRILL_DATABASE') ?: env('DB_DATABASE', 'store').'_restore_drill',
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

    // Cloudflare Turnstile on customer sign-in, registration, reset requests and order placement
    // (spec §8). Empty = off. The matching site key is the storefront's NEXT_PUBLIC_TURNSTILE_SITE_KEY.
    'turnstile' => [
        'secret_key' => env('TURNSTILE_SECRET_KEY', ''),
    ],

    // Admin sessions: signed out after this many hours however active
    // (ASVS 3.3.2). Idle sign-out is SESSION_LIFETIME (minutes, 30 by default).
    'panels' => [
        'max_session_hours' => (float) env('PANEL_MAX_SESSION_HOURS', 12),
    ],

    'passwords' => [
        // Have I Been Pwned k-anonymity check. Disabled only in the test suite.
        'check_breached' => (bool) env('PASSWORD_BREACH_CHECK', true),
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
        // Install-time default for the `store.url` setting, which the admin owns afterwards.
        'url' => env('STOREFRONT_URL'),
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
