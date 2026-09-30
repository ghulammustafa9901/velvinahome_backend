<?php

declare(strict_types=1);

namespace Modules\Pricing\Support;

use Modules\Catalog\Data\SellableVariant;
use Modules\Pricing\Models\Promotion;
use Modules\Support\ValueObjects\Money;

/**
 * Applies the running automatic promotions to priced lines, before any coupon.
 *
 * Promotions are taken in priority order and each line can be discounted by one promotion
 * only (the first that claims it), so promotions never stack on the same item. Order-level
 * amounts are spread over the lines with Money::allocate, so line discounts always add up.
 */
final class PromotionEngine
{
    /**
     * @param  list<array{variant: SellableVariant, quantity: int, subtotal: Money}>  $lines
     * @return array{0: list<Money>, 1: list<array{name: string, amount: Money}>} per-line discounts and the promotions applied
     */
    public function apply(array $lines, Money $zero): array
    {
        $discounts = array_fill(0, count($lines), $zero);
        $claimed = array_fill(0, count($lines), false);
        $applied = [];

        if ($lines === []) {
            return [$discounts, $applied];
        }

        foreach (Promotion::query()->running()->get() as $promotion) {
            $eligible = [];
            foreach ($lines as $index => $line) {
                if (! $claimed[$index] && Eligibility::matches($promotion->conditions, $line['variant']->productUlid, $line['variant']->categoryUlids)) {
                    $eligible[] = $index;
                }
            }

            if ($eligible === []) {
                continue;
            }

            $amounts = match ($promotion->type) {
                'percentage_sale' => $this->percentageSale($promotion, $lines, $eligible),
                'spend_tier' => $this->spendTier($promotion, $lines, $eligible, $zero),
                'buy_x_get_y' => $this->buyXGetY($promotion, $lines, $eligible, $zero),
                default => [],
            };

            $total = Money::sum(array_values($amounts), $zero->currency);

            if ($total->isZero()) {
                continue;
            }

            foreach ($eligible as $index) {
                $claimed[$index] = true;
                $discounts[$index] = $amounts[$index] ?? $zero;
            }

            $applied[] = ['name' => $promotion->name, 'amount' => $total];
        }

        return [array_values($discounts), $applied];
    }

    /**
     * @param  list<array{variant: SellableVariant, quantity: int, subtotal: Money}>  $lines
     * @param  list<int>  $eligible
     * @return array<int, Money>
     */
    private function percentageSale(Promotion $promotion, array $lines, array $eligible): array
    {
        $bps = (int) ($promotion->config['percent_bps'] ?? 0);
        $amounts = [];

        foreach ($eligible as $index) {
            $amounts[$index] = $lines[$index]['subtotal']->percentage($bps);
        }

        return $amounts;
    }

    /**
     * @param  list<array{variant: SellableVariant, quantity: int, subtotal: Money}>  $lines
     * @param  list<int>  $eligible
     * @return array<int, Money>
     */
    private function spendTier(Promotion $promotion, array $lines, array $eligible, Money $zero): array
    {
        $spend = Money::sum(array_map(fn (int $index): Money => $lines[$index]['subtotal'], $eligible), $zero->currency);
        $best = null;

        foreach ((array) ($promotion->config['tiers'] ?? []) as $tier) {
            if (is_array($tier) && $spend->amount >= (int) ($tier['min_spend'] ?? PHP_INT_MAX) && ($best === null || (int) $tier['min_spend'] > (int) $best['min_spend'])) {
                $best = $tier;
            }
        }

        if ($best === null) {
            return [];
        }

        $total = ($best['type'] ?? '') === 'percentage'
            ? $spend->percentage((int) $best['value'])
            : Money::min(Money::of((int) ($best['value'] ?? 0), $zero->currency), $spend);

        return $this->spread($total, $lines, $eligible);
    }

    /**
     * @param  list<array{variant: SellableVariant, quantity: int, subtotal: Money}>  $lines
     * @param  list<int>  $eligible
     * @return array<int, Money>
     */
    private function buyXGetY(Promotion $promotion, array $lines, array $eligible, Money $zero): array
    {
        $buy = max(1, (int) ($promotion->config['buy'] ?? 1));
        $get = max(1, (int) ($promotion->config['get'] ?? 1));
        $bps = (int) ($promotion->config['percent_bps'] ?? 10000);

        // Every eligible unit, dearest first; in each full group of buy+get units the last
        // `get` (the cheapest of the group) are discounted.
        $units = [];
        foreach ($eligible as $index) {
            for ($n = 0; $n < $lines[$index]['quantity']; $n++) {
                $units[] = [$index, $lines[$index]['variant']->price];
            }
        }

        usort($units, fn (array $a, array $b): int => $b[1]->amount <=> $a[1]->amount ?: $a[0] <=> $b[0]);

        $amounts = [];
        $groupSize = $buy + $get;
        $full = intdiv(count($units), $groupSize) * $groupSize;

        for ($position = 0; $position < $full; $position++) {
            if ($position % $groupSize < $buy) {
                continue;
            }

            [$index, $price] = $units[$position];
            $amounts[$index] = ($amounts[$index] ?? $zero)->plus($price->percentage($bps));
        }

        return $amounts;
    }

    /**
     * @param  list<array{variant: SellableVariant, quantity: int, subtotal: Money}>  $lines
     * @param  list<int>  $eligible
     * @return array<int, Money>
     */
    private function spread(Money $total, array $lines, array $eligible): array
    {
        if ($total->isZero()) {
            return [];
        }

        $parts = $total->allocate(...array_map(fn (int $index): int => $lines[$index]['subtotal']->amount, $eligible));

        return array_combine($eligible, $parts);
    }
}
