<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Queues (spec §6.11)
|--------------------------------------------------------------------------
|
| One Redis queue connection. Named queues by priority: critical (payments, webhooks),
| default, notifications, search, media, reports.
|
*/

return [

    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 120),
            'block_for' => null,
            // Jobs are dispatched after the surrounding transaction commits, so a job can
            // never observe (or announce) a write that was rolled back (spec §3.4.6).
            'after_commit' => true,
        ],

    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],

];
