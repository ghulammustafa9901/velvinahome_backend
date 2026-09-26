<?php

declare(strict_types=1);

use Modules\Support\Enums\Currency;
use Modules\Support\Exceptions\CurrencyMismatch;
use Modules\Support\Exceptions\InvalidAllocation;
use Modules\Support\ValueObjects\Money;

it('stores minor units exactly', function (): void {
    $money = Money::of(249900, Currency::PKR);

    expect($money->amount)->toBe(249900)
        ->and($money->currency)->toBe(Currency::PKR)
        ->and($money->toDecimalString())->toBe('2499.00');
});

it('builds from whole major units', function (): void {
    expect(Money::fromMajorUnits(2499, Currency::PKR)->amount)->toBe(249900);
});

it('accepts a currency code string', function (): void {
    expect(Money::of(100, 'PKR')->currency)->toBe(Currency::PKR);
});

it('adds and subtracts without drift', function (): void {
    $a = Money::of(10, Currency::PKR);

    // The classic float failure: 0.1 + 0.2 !== 0.3. In minor units it simply cannot happen.
    $sum = $a->plus(Money::of(20, Currency::PKR));

    expect($sum->amount)->toBe(30)
        ->and($sum->minus(Money::of(30, Currency::PKR))->isZero())->toBeTrue();
});

it('refuses to combine different currencies', function (): void {
    Money::of(100, Currency::PKR)->plus(Money::of(100, Currency::USD));
})->throws(CurrencyMismatch::class);

it('refuses to compare different currencies', function (): void {
    Money::of(100, Currency::PKR)->greaterThan(Money::of(1, Currency::USD));
})->throws(CurrencyMismatch::class);

it('multiplies by a quantity', function (): void {
    expect(Money::of(24990, Currency::PKR)->multipliedBy(3)->amount)->toBe(74970);
});

describe('percentage in basis points', function (): void {
    it('applies a whole percentage', function (): void {
        // 17% of Rs 1,000.00
        expect(Money::of(100000, Currency::PKR)->percentage(1700)->amount)->toBe(17000);
    });

    it('applies a fractional percentage', function (): void {
        // 2.5% of Rs 1,000.00
        expect(Money::of(100000, Currency::PKR)->percentage(250)->amount)->toBe(2500);
    });

    it('rounds half away from zero', function (): void {
        // 50% of 5 paisa is 2.5 paisa, which must land on 3, not 2.
        expect(Money::of(5, Currency::PKR)->percentage(5000)->amount)->toBe(3);
    });

    it('rounds a negative amount symmetrically', function (): void {
        // A refund of a discount must be the same magnitude as the discount.
        expect(Money::of(-5, Currency::PKR)->percentage(5000)->amount)->toBe(-3);
    });
});

describe('allocation', function (): void {
    it('loses no minor unit when splitting evenly', function (): void {
        $shares = Money::of(10000, Currency::PKR)->split(3);

        expect(array_map(fn (Money $m): int => $m->amount, $shares))->toBe([3334, 3333, 3333])
            ->and(Money::sum($shares)->amount)->toBe(10000);
    });

    it('distributes by weight', function (): void {
        // An order-level discount spread across line totals of 700 and 300.
        $shares = Money::of(1000, Currency::PKR)->allocate(700, 300);

        expect(array_map(fn (Money $m): int => $m->amount, $shares))->toBe([700, 300]);
    });

    it('gives the leftover to the largest remainder', function (): void {
        $shares = Money::of(100, Currency::PKR)->allocate(1, 1, 1);

        expect(array_map(fn (Money $m): int => $m->amount, $shares))->toBe([34, 33, 33])
            ->and(Money::sum($shares)->amount)->toBe(100);
    });

    it('allocates a negative amount without losing a unit', function (): void {
        $shares = Money::of(-100, Currency::PKR)->allocate(1, 1, 1);

        expect(Money::sum($shares)->amount)->toBe(-100);
    });

    it('handles a zero weight', function (): void {
        $shares = Money::of(100, Currency::PKR)->allocate(1, 0);

        expect(array_map(fn (Money $m): int => $m->amount, $shares))->toBe([100, 0]);
    });

    it('rejects weights that are all zero', function (): void {
        Money::of(100, Currency::PKR)->allocate(0, 0);
    })->throws(InvalidAllocation::class);

    it('rejects a negative weight', function (): void {
        Money::of(100, Currency::PKR)->allocate(-1, 2);
    })->throws(InvalidAllocation::class);

    it('rejects splitting into fewer than one part', function (): void {
        Money::of(100, Currency::PKR)->split(0);
    })->throws(InvalidAllocation::class);
});

describe('comparison', function (): void {
    it('compares amounts', function (): void {
        $low = Money::of(100, Currency::PKR);
        $high = Money::of(200, Currency::PKR);

        expect($low->lessThan($high))->toBeTrue()
            ->and($high->greaterThan($low))->toBeTrue()
            ->and($low->lessThanOrEqualTo($low))->toBeTrue()
            ->and($low->equals(Money::of(100, Currency::PKR)))->toBeTrue();
    });

    it('treats the same amount in different currencies as unequal', function (): void {
        expect(Money::of(100, Currency::PKR)->equals(Money::of(100, Currency::USD)))->toBeFalse();
    });

    it('finds the minimum and maximum', function (): void {
        $a = Money::of(100, Currency::PKR);
        $b = Money::of(300, Currency::PKR);
        $c = Money::of(200, Currency::PKR);

        expect(Money::min($a, $b, $c)->amount)->toBe(100)
            ->and(Money::max($a, $b, $c)->amount)->toBe(300);
    });
});

describe('sum', function (): void {
    it('sums a list', function (): void {
        $total = Money::sum([
            Money::of(100, Currency::PKR),
            Money::of(250, Currency::PKR),
        ]);

        expect($total->amount)->toBe(350);
    });

    it('returns zero for an empty list with an explicit currency', function (): void {
        expect(Money::sum([], Currency::PKR)->isZero())->toBeTrue();
    });

    it('refuses an empty list without a currency', function (): void {
        Money::sum([]);
    })->throws(InvalidAllocation::class);
});

describe('formatting', function (): void {
    it('omits decimals for whole amounts', function (): void {
        expect(Money::of(249900, Currency::PKR)->format())->toBe('Rs 2,499');
    });

    it('shows decimals when there is a fractional part', function (): void {
        expect(Money::of(249950, Currency::PKR)->format())->toBe('Rs 2,499.50');
    });

    it('groups large amounts', function (): void {
        expect(Money::of(123456789, Currency::PKR)->format())->toBe('Rs 1,234,567.89');
    });

    it('formats a negative amount', function (): void {
        expect(Money::of(-150000, Currency::PKR)->format())->toBe('-Rs 1,500');
    });

    it('formats zero', function (): void {
        expect(Money::zero(Currency::PKR)->format())->toBe('Rs 0');
    });

    it('uses the currency symbol', function (): void {
        expect(Money::of(1050, Currency::USD)->format())->toBe('$ 10.50');
    });

    it('stays exact for amounts beyond float precision', function (): void {
        // 2^53 + 1 minor units: a float would round this; the integer path must not.
        $amount = 9007199254740993;

        expect(Money::of($amount, Currency::PKR)->toDecimalString())->toBe('90071992547409.93');
    });
});

it('serialises to the API money shape', function (): void {
    expect(Money::of(249900, Currency::PKR)->jsonSerialize())->toBe([
        'amount' => 249900,
        'currency' => 'PKR',
        'formatted' => 'Rs 2,499',
    ]);
});

it('negates and absolutes', function (): void {
    expect(Money::of(100, Currency::PKR)->negated()->amount)->toBe(-100)
        ->and(Money::of(-100, Currency::PKR)->absolute()->amount)->toBe(100);
});

it('reports sign', function (): void {
    expect(Money::of(1, Currency::PKR)->isPositive())->toBeTrue()
        ->and(Money::of(-1, Currency::PKR)->isNegative())->toBeTrue()
        ->and(Money::zero(Currency::PKR)->isZero())->toBeTrue();
});

it('is immutable', function (): void {
    $original = Money::of(100, Currency::PKR);
    $original->plus(Money::of(50, Currency::PKR));

    expect($original->amount)->toBe(100);
});

it('casts to a debuggable string', function (): void {
    expect((string) Money::of(249900, Currency::PKR))->toBe('PKR 2499.00');
});
