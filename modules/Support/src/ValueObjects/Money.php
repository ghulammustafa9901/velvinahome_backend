<?php

declare(strict_types=1);

namespace Modules\Support\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Modules\Support\Enums\Currency;
use Modules\Support\Exceptions\CurrencyMismatch;
use Modules\Support\Exceptions\InvalidAllocation;
use Stringable;

/**
 * An exact monetary amount.
 *
 * Money is an integer count of minor units (paisa for PKR) plus a currency — never a float
 * and never a decimal string that becomes a float somewhere downstream (spec §6.3,
 * ADR-0008). Every arithmetic operation here is integer arithmetic, so order totals, tax
 * lines, refunds and weighted-average cost cannot accumulate rounding drift.
 *
 * Immutable: every operation returns a new instance.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public int $amount,
        public Currency $currency,
    ) {}

    /**
     * @param  int  $amount  minor units, e.g. 249900 for Rs 2,499.00
     */
    public static function of(int $amount, Currency|string $currency): self
    {
        return new self($amount, self::resolveCurrency($currency));
    }

    public static function zero(Currency|string $currency): self
    {
        return new self(0, self::resolveCurrency($currency));
    }

    /**
     * Build from major units. Accepts only whole major units, because anything with a
     * fractional part belongs in `of()` as minor units — this method exists for readable
     * test data and seeders, not for parsing user input.
     */
    public static function fromMajorUnits(int $majorUnits, Currency|string $currency): self
    {
        $resolved = self::resolveCurrency($currency);

        return new self($majorUnits * $resolved->minorUnitsPerMajor(), $resolved);
    }

    /**
     * Parse a human-entered decimal amount ("2499", "2,499.50", "0.5") into minor units by
     * string arithmetic — never through a float. More fractional digits than the currency
     * has are rejected rather than rounded, so nobody's price is silently changed.
     */
    public static function parse(string $value, Currency|string $currency): self
    {
        $resolved = self::resolveCurrency($currency);
        $clean = str_replace([',', ' '], '', trim($value));

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $clean, $parts) !== 1) {
            throw new InvalidArgumentException("[{$value}] is not a valid amount.");
        }

        $digits = $resolved->minorUnitDigits();
        $fraction = $parts[3] ?? '';

        if (strlen($fraction) > $digits) {
            throw new InvalidArgumentException("[{$value}] has more than {$digits} decimal places.");
        }

        $minor = (int) ($parts[2].str_pad($fraction, $digits, '0'));

        return new self($parts[1] === '-' ? -$minor : $minor, $resolved);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    /**
     * Multiply by a whole number, e.g. a line quantity. There is deliberately no
     * multiplication by a float: a fractional rate belongs in percentage().
     */
    public function multipliedBy(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    /**
     * Take a percentage expressed in basis points: 1700 is 17%, 250 is 2.5%.
     *
     * Rates are stored as integer basis points precisely so this stays integer arithmetic
     * (spec §6.3). Rounds half away from zero, so a discount and its mirror refund round
     * to the same magnitude.
     */
    public function percentage(int $basisPoints): self
    {
        $sign = $this->amount < 0 ? -1 : 1;
        $scaled = abs($this->amount) * $basisPoints;

        $whole = intdiv($scaled, 10_000);
        $remainder = $scaled % 10_000;

        if ($remainder * 2 >= 10_000) {
            $whole++;
        }

        return new self($sign * $whole, $this->currency);
    }

    /**
     * Split the amount across weighted shares without losing or inventing a single minor
     * unit: the returned amounts always sum exactly back to this amount.
     *
     * Uses the largest-remainder method, so distributing a Rs 100 discount across three
     * lines gives 3334/3333/3333 rather than three lots of 3333 and a lost paisa. This is
     * the operation that makes order-level discounts reconcile against line totals.
     *
     * @param  int  ...$weights  non-negative, at least one greater than zero
     * @return list<self>
     */
    public function allocate(int ...$weights): array
    {
        if ($weights === []) {
            throw InvalidAllocation::noWeights();
        }

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw InvalidAllocation::negativeWeight($weight);
            }
        }

        $total = array_sum($weights);

        if ($total === 0) {
            throw InvalidAllocation::zeroTotalWeight();
        }

        $sign = $this->amount < 0 ? -1 : 1;
        $absolute = abs($this->amount);

        $shares = [];
        $remainders = [];
        $allocated = 0;

        foreach ($weights as $index => $weight) {
            $scaled = $absolute * $weight;
            $shares[$index] = intdiv($scaled, $total);
            $remainders[$index] = $scaled % $total;
            $allocated += $shares[$index];
        }

        // Hand the leftover minor units to the largest remainders first. arsort is stable,
        // so ties resolve by original position and the result is deterministic.
        $leftover = $absolute - $allocated;
        arsort($remainders, SORT_NUMERIC);

        foreach (array_keys($remainders) as $index) {
            if ($leftover <= 0) {
                break;
            }

            $shares[$index]++;
            $leftover--;
        }

        ksort($shares);

        return array_values(array_map(
            fn (int $share): self => new self($sign * $share, $this->currency),
            $shares,
        ));
    }

    /**
     * Split into equal parts, distributing any remainder one minor unit at a time.
     *
     * @return list<self>
     */
    public function split(int $parts): array
    {
        if ($parts < 1) {
            throw InvalidAllocation::invalidPartCount($parts);
        }

        return $this->allocate(...array_fill(0, $parts, 1));
    }

    public function negated(): self
    {
        return new self(-$this->amount, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->amount), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount <=> $other->amount;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function lessThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function greaterThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public static function min(self $first, self ...$rest): self
    {
        $minimum = $first;

        foreach ($rest as $candidate) {
            if ($candidate->lessThan($minimum)) {
                $minimum = $candidate;
            }
        }

        return $minimum;
    }

    public static function max(self $first, self ...$rest): self
    {
        $maximum = $first;

        foreach ($rest as $candidate) {
            if ($candidate->greaterThan($maximum)) {
                $maximum = $candidate;
            }
        }

        return $maximum;
    }

    /**
     * Sum a list of amounts. The currency comes from the first element, so an empty list
     * needs an explicit currency — there is no such thing as a currency-less zero.
     *
     * @param  list<self>  $amounts
     */
    public static function sum(array $amounts, Currency|string|null $currency = null): self
    {
        if ($amounts === []) {
            if ($currency === null) {
                throw InvalidAllocation::cannotSumEmptyWithoutCurrency();
            }

            return self::zero($currency);
        }

        $total = $amounts[0];

        foreach (array_slice($amounts, 1) as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    /**
     * The amount as a plain decimal string, e.g. "2499.00". Suitable for exports; use
     * format() for anything a person reads.
     */
    public function toDecimalString(): string
    {
        $digits = $this->currency->minorUnitDigits();

        if ($digits === 0) {
            return (string) $this->amount;
        }

        $sign = $this->amount < 0 ? '-' : '';
        $absolute = abs($this->amount);
        $perMajor = $this->currency->minorUnitsPerMajor();

        $major = intdiv($absolute, $perMajor);
        $minor = $absolute % $perMajor;

        return $sign.$major.'.'.str_pad((string) $minor, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Human-readable, grouped by thousands. Whole amounts omit the decimals, because
     * "Rs 2,499" is how prices are written in this market; fractional amounts show the
     * currency's full precision so nothing is ever hidden.
     *
     * Formatting is a server responsibility (ADR-0008): clients render what they are given.
     */
    public function format(): string
    {
        $digits = $this->currency->minorUnitDigits();
        $perMajor = $this->currency->minorUnitsPerMajor();

        $sign = $this->amount < 0 ? '-' : '';
        $absolute = abs($this->amount);

        $major = intdiv($absolute, $perMajor);
        $minor = $absolute % $perMajor;

        $formatted = self::groupThousands($major);

        if ($minor !== 0) {
            $formatted .= '.'.str_pad((string) $minor, $digits, '0', STR_PAD_LEFT);
        }

        return $sign.$this->currency->symbol().' '.$formatted;
    }

    /**
     * Insert thousands separators without going through a float.
     *
     * number_format() takes a float, and routing a money amount through one — even only for
     * display — is exactly the habit ADR-0008 forbids. String arithmetic on the integer keeps
     * large amounts exact.
     */
    private static function groupThousands(int $value): string
    {
        $digits = (string) abs($value);
        $groups = [];

        while (strlen($digits) > 3) {
            $groups[] = substr($digits, -3);
            $digits = substr($digits, 0, -3);
        }

        $groups[] = $digits;

        return implode(',', array_reverse($groups));
    }

    /**
     * The API shape for money (api-conventions.md §2): the exact integer, the currency, and
     * a server-formatted string, so no client performs currency arithmetic or formatting.
     *
     * @return array{amount: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency->value,
            'formatted' => $this->format(),
        ];
    }

    public function __toString(): string
    {
        return $this->currency->value.' '.$this->toDecimalString();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }

    private static function resolveCurrency(Currency|string $currency): Currency
    {
        return $currency instanceof Currency ? $currency : Currency::from($currency);
    }
}
