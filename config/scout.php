<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Laravel Scout + Typesense (spec §6.10, ADR-0005)
|--------------------------------------------------------------------------
|
| Collections are per tenant — the model's searchableAs() adds the tenant id — and schemas
| and search parameters live on the model (Modules\Catalog\Support\ProductSearch).
| Indexing is queued on the `search` queue and only after the surrounding transaction
| commits, so a rolled-back save never leaves a phantom document.
|
*/

return [

    'driver' => env('SCOUT_DRIVER', 'typesense'),

    // Separates environments sharing one Typesense node (the test suite uses `test_`).
    'prefix' => env('SCOUT_PREFIX', ''),

    'queue' => [
        'connection' => env('SCOUT_QUEUE_CONNECTION'),
        'queue' => 'search',
    ],

    'after_commit' => true,

    'chunk' => [
        'searchable' => 200,
        'unsearchable' => 200,
    ],

    'soft_delete' => false,

    'identify' => false,

    'typesense' => [
        'client-settings' => [
            'api_key' => env('TYPESENSE_API_KEY', ''),
            'nodes' => [
                [
                    'host' => env('TYPESENSE_HOST', '127.0.0.1'),
                    'port' => env('TYPESENSE_PORT', '8108'),
                    'path' => env('TYPESENSE_PATH', ''),
                    'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
                ],
            ],
            'nearest_node' => null,
            'connection_timeout_seconds' => (int) env('TYPESENSE_CONNECTION_TIMEOUT_SECONDS', 2),
            'healthcheck_interval_seconds' => (int) env('TYPESENSE_HEALTHCHECK_INTERVAL_SECONDS', 30),
            'num_retries' => (int) env('TYPESENSE_NUM_RETRIES', 3),
            'retry_interval_seconds' => (int) env('TYPESENSE_RETRY_INTERVAL_SECONDS', 1),
        ],
        'model-settings' => [],
    ],

];
