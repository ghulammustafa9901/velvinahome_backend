<?php

declare(strict_types=1);

namespace Modules\Support\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Support\Enums\Currency;
use Modules\Support\ValueObjects\Money;

/**
 * Casts a `<name>_amount` / `<name>_currency` column pair to a Money value object.
 *
 * Declared on the logical attribute, not on either column:
 *
 *     protected function casts(): array
 *     {
 *         return ['total' => MoneyCast::class];
 *     }
 *
 * so `$order->total` is Money and `$order->total = Money::of(249900, 'PKR')` writes both
 * columns. Nothing in the application reads a bare `*_amount` integer, which is how the
 * "money never becomes a float" rule in ADR-0008 stays true in practice rather than by
 * discipline.
 *
 * @implements CastsAttributes<Money|null, Money|null>
 */
final class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $amount = $attributes[$this->amountColumn($key)] ?? null;
        $currency = $attributes[$this->currencyColumn($key)] ?? null;

        if ($amount === null || $currency === null) {
            return null;
        }

        return Money::of((int) $amount, Currency::from((string) $currency));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, int|string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [
                $this->amountColumn($key) => null,
                $this->currencyColumn($key) => null,
            ];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException(sprintf(
                'Attribute [%s] expects a %s instance, %s given. Money is never assigned as a raw number.',
                $key,
                Money::class,
                get_debug_type($value),
            ));
        }

        return [
            $this->amountColumn($key) => $value->amount,
            $this->currencyColumn($key) => $value->currency->value,
        ];
    }

    private function amountColumn(string $key): string
    {
        return $key.'_amount';
    }

    private function currencyColumn(string $key): string
    {
        return $key.'_currency';
    }
}
