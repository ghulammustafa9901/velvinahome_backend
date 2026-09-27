<?php

declare(strict_types=1);

namespace Modules\Tax\Support;

use Modules\Settings\Contracts\TenantSettings;
use Modules\Support\ValueObjects\Money;
use Modules\Tax\Contracts\TaxCalculator;
use Modules\Tax\Data\TaxLineData;
use Modules\Tax\Models\TaxRate;

/**
 * Integer tax arithmetic:
 *
 *  - exclusive: tax = amount × rate / 10 000, rounded half away from zero;
 *  - inclusive: tax = amount × rate / (10 000 + rate) — the tax share already inside a
 *    tax-inclusive price, so that net + tax is exactly the price the customer saw.
 */
final class RateTableTaxCalculator implements TaxCalculator
{
    /** @var array<string, TaxRate|null> */
    private array $resolved = [];

    public function __construct(private readonly TenantSettings $settings) {}

    public function tax(Money $amount, ?string $countryCode, ?string $province): TaxLineData
    {
        $inclusive = $this->settings->string('tax.mode', 'inclusive') === 'inclusive';
        $rate = $this->rateFor($countryCode, $province);

        if ($rate === null || $rate->rate_bps === 0) {
            return new TaxLineData('No tax', 0, Money::zero($amount->currency), $inclusive);
        }

        $taxAmount = $inclusive
            ? self::divideRounded($amount->amount * $rate->rate_bps, 10_000 + $rate->rate_bps)
            : $amount->percentage($rate->rate_bps)->amount;

        return new TaxLineData($rate->name, $rate->rate_bps, Money::of($taxAmount, $amount->currency), $inclusive);
    }

    private function rateFor(?string $countryCode, ?string $province): ?TaxRate
    {
        $key = strtoupper((string) $countryCode).'|'.mb_strtolower((string) $province);

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $candidates = TaxRate::query()->where('is_active', true)->get();

        $best = $candidates
            ->filter(fn (TaxRate $rate): bool => ($rate->country_code === null || strtoupper($rate->country_code) === strtoupper((string) $countryCode))
                && ($rate->province === null || mb_strtolower($rate->province) === mb_strtolower((string) $province)))
            ->sortByDesc(fn (TaxRate $rate): int => ($rate->country_code !== null ? 1 : 0) + ($rate->province !== null ? 2 : 0))
            ->first();

        return $this->resolved[$key] = $best;
    }

    private static function divideRounded(int $numerator, int $denominator): int
    {
        $sign = $numerator < 0 ? -1 : 1;

        return $sign * intdiv(abs($numerator) * 2 + $denominator, $denominator * 2);
    }
}
