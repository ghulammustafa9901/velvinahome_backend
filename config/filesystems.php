<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Object storage (ADR-0004): one bucket, everything under OBJECT_STORAGE_PREFIX, so two
         * installations can share a bucket without sharing files. OBJECT_STORAGE_DRIVER=local
         * keeps files on disk for machines without MinIO (ADR-0009) — never in production,
         * where uploads never touch the app server.
         */
        'media' => env('OBJECT_STORAGE_DRIVER', 's3') === 'local' ? [
            'driver' => 'local',
            'root' => storage_path('app/media'),
            // Served by the API itself (dev only), see Catalog routes.
            'url' => '/api/v1/media',
            'visibility' => 'public',
            'throw' => true,
        ] : [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => env('R2_REGION', 'auto'),
            'bucket' => env('R2_BUCKET'),
            'root' => trim((string) env('OBJECT_STORAGE_PREFIX', 'store'), '/').'/media',
            'url' => env('R2_PUBLIC_URL'),
            'endpoint' => env('R2_ENDPOINT'),
            'use_path_style_endpoint' => env('R2_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'public',
            'throw' => true,
        ],

        // Invoices, exports, payment receipts: private, served only through signed,
        // expiring URLs (spec §6.9).
        'private' => env('OBJECT_STORAGE_DRIVER', 's3') === 'local' ? [
            'driver' => 'local',
            'root' => storage_path('app/private-objects'),
            'visibility' => 'private',
            'throw' => true,
        ] : [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => env('R2_REGION', 'auto'),
            'bucket' => env('R2_BUCKET'),
            'root' => trim((string) env('OBJECT_STORAGE_PREFIX', 'store'), '/').'/private',
            'endpoint' => env('R2_ENDPOINT'),
            'use_path_style_endpoint' => env('R2_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
