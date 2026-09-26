<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Queues (spec §6.11, docs/architecture/tenancy.md §3)
|--------------------------------------------------------------------------
|
| One Redis queue connection shared by every tenant. Correctness does not come from
| separate queues per tenant but from the tenant id that QueueTenancyBootstrapper stamps
| into every payload dispatched in tenant context, and from TenantAware jobs refusing to
| run without it.
|
| Named queues by priority: critical (payments, webhooks), default, notifications, search,
| media, reports.
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

        // Landlord-only work (e.g. the cross-tenant scheduler fan-out). Payloads dispatched
        // on this connection deliberately carry no tenant id.
        'central' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => 'central',
            'retry_after' => 120,
            'block_for' => null,
            'after_commit' => true,
            'central' => true,
        ],

    ],

    'batching' => [
        'database' => 'landlord',
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => 'landlord',
        'table' => 'failed_jobs',
    ],

];
