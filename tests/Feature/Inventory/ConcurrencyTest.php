<?php

declare(strict_types=1);

use Modules\Inventory\Actions\ReceiveStock;
use Modules\Inventory\Data\StockDocumentData;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestCatalog;

/*
| Spec §6.5 rule 2, §12.2: concurrency tests for stock reservation — no overselling.
| Separate PHP processes, separate database connections, one real PostgreSQL.
*/

it('sells the last units exactly once when many buyers race for them', function (int $stock, int $buyers): void {
    $variant = TestCatalog::simpleProduct()->variants()->firstOrFail();

    app(ReceiveStock::class)->handle(StockDocumentData::from([
        'locationUlid' => TestCatalog::defaultLocation()->ulid,
        'lines' => [['variantUlid' => $variant->ulid, 'quantity' => $stock]],
    ]));

    $php = (string) (new PhpExecutableFinder)->find(false);
    $startAt = microtime(true) + 4;
    $processes = [];

    for ($i = 0; $i < $buyers; $i++) {
        $process = new Process(
            [$php, base_path('tests/Support/Concurrency/reserve.php'), $variant->ulid, (string) $startAt],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'SCOUT_PREFIX' => 'test_',
            ],
            timeout: 60,
        );
        $process->start();
        $processes[] = $process;
    }

    $outcomes = [];
    foreach ($processes as $process) {
        $process->wait();
        $outcomes[] = trim($process->getOutput()) ?: 'ERR: '.$process->getErrorOutput();
    }

    $counts = array_count_values($outcomes);

    expect($counts['OK'] ?? 0)->toBe($stock, implode(' | ', $outcomes))
        ->and($counts['NO'] ?? 0)->toBe($buyers - $stock);

    $level = StockLevel::query()->where('variant_id', $variant->id)->firstOrFail();

    expect($level->reserved)->toBe($stock)
        ->and($level->on_hand - $level->reserved)->toBe(0)
        ->and(StockReservation::query()->where('variant_id', $variant->id)->where('status', 'active')->count())->toBe($stock);
})->with([
    'one unit, ten buyers' => [1, 10],
    'three units, twelve buyers' => [3, 12],
]);
