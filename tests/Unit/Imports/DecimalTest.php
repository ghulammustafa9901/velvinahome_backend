<?php

declare(strict_types=1);

use Modules\Imports\Support\Decimal;
use Modules\Imports\Support\Text;

it('turns decimal strings into integers without floating point', function (mixed $input, int $digits, ?int $expected): void {
    expect(Decimal::scaled($input, $digits))->toBe($expected);
})->with([
    ['1299.50', 2, 129_950],
    ['1299.5', 2, 129_950],
    ['1299', 2, 129_900],
    [1299, 2, 129_900],
    ['0.005', 2, 1],
    ['0.004', 2, 0],
    ['19.999', 2, 2000],
    ['0.85', 3, 850],
    [' 12.30 ', 2, 1230],
    ['-5.25', 2, -525],
    ['', 2, null],
    ['1,299.00', 2, null],
    ['1e3', 2, null],
    [null, 2, null],
]);

it('turns editor HTML into plain text with paragraphs', function (): void {
    expect(Text::plain('<p>Soft <strong>linen</strong> &amp; cotton.</p><p>Made in Lahore.</p><script>x</script>', 100))
        ->toBe("Soft linen & cotton.\nMade in Lahore.")
        ->and(Text::plain('<p> </p>', 100))->toBeNull()
        ->and(Text::plain('abcdef', 3))->toBe('abc');
});
