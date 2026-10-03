<?php

declare(strict_types=1);

/*
| Child process for the stock concurrency test: boots the real application, waits for a common
| start instant so every child hits the database together, then tries to reserve one unit.
| Prints OK or NO.
|
| Usage: php reserve.php <variant-ulid> <start-at-microtime>
*/

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Modules\Inventory\Actions\ReserveStock;
use Modules\Inventory\Exceptions\InsufficientStock;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $variantUlid, $startAt] = $argv;

app(ReserveStock::class); // resolve everything before the race

while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    app(ReserveStock::class)->handle($variantUlid, 1, 'cart', (string) Str::ulid());
    echo 'OK';
} catch (InsufficientStock) {
    echo 'NO';
}
