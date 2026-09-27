<?php

declare(strict_types=1);

use Modules\Support\Enums\Currency;
use Modules\Support\ValueObjects\Money;

it('parses human-entered amounts into exact minor units', function (string $input, int $minor): void {
    expect(Money::parse($input, Currency::PKR)->amount)->toBe($minor);
})->with([
    'whole' => ['2499', 249900],
    'grouped' => ['2,499', 249900],
    'two decimals' => ['2499.50', 249950],
    'one decimal' => ['0.5', 50],
    'large beyond float precision' => ['90071992547409.93', 9007199254740993],
    'negative' => ['-10.25', -1025],
]);

it('rejects input it cannot represent exactly', function (string $input): void {
    Money::parse($input, Currency::PKR);
})->throws(InvalidArgumentException::class)->with(['1.005', 'abc', '1e3', '', '.5']);
