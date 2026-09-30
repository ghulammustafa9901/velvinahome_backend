<?php

declare(strict_types=1);

namespace Modules\Pricing\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Validation\ValidationException;
use Modules\Pricing\Models\Promotion;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Create or change an automatic promotion. The configuration is checked per type here, so the
 * pricing pipeline can trust what it reads (see Promotion for the shapes).
 */
final class SavePromotion implements Action
{
    use ChecksPermission;

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $categoryUlids
     * @param  list<string>  $productUlids
     */
    public function handle(
        ?Promotion $promotion,
        string $name,
        string $type,
        array $config,
        bool $isActive = true,
        int $priority = 0,
        ?CarbonImmutable $startsAt = null,
        ?CarbonImmutable $endsAt = null,
        array $categoryUlids = [],
        array $productUlids = [],
        ?Authorizable $actor = null,
    ): Promotion {
        $this->authorizePermission($actor, 'promotions.manage');

        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if (trim($name) === '' || mb_strlen($name) > 120) {
            $fail('name', __('Give the promotion a name of up to 120 characters.'));
        }

        if (! in_array($type, Promotion::TYPES, true)) {
            $fail('type', __('Unknown promotion type.'));
        }

        if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
            $fail('ends_at', __('The end must be after the start.'));
        }

        $bps = fn (mixed $value): bool => is_int($value) && $value >= 1 && $value <= 10000;
        $count = fn (mixed $value): bool => is_int($value) && $value >= 1 && $value <= 100;

        $clean = match ($type) {
            'percentage_sale' => $bps($config['percent_bps'] ?? null)
                ? ['percent_bps' => $config['percent_bps']]
                : $fail('config', __('The discount must be between 0.01% and 100%.')),
            'buy_x_get_y' => $count($config['buy'] ?? null) && $count($config['get'] ?? null) && $bps($config['percent_bps'] ?? null)
                ? ['buy' => $config['buy'], 'get' => $config['get'], 'percent_bps' => $config['percent_bps']]
                : $fail('config', __('Buy and get must be 1–100 items, the discount 0.01%–100%.')),
            default => ['tiers' => $this->tiers($config['tiers'] ?? null, $fail)],
        };

        $promotion ??= new Promotion;
        $promotion->fill([
            'name' => trim($name),
            'type' => $type,
            'config' => $clean,
            'is_active' => $isActive,
            'priority' => $priority,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'conditions' => array_filter([
                'category_ulids' => array_values(array_unique($categoryUlids)),
                'product_ulids' => array_values(array_unique($productUlids)),
            ]),
        ])->save();

        return $promotion;
    }

    /**
     * @param  callable(string, string): never  $fail
     * @return list<array{min_spend: int, type: string, value: int}>
     */
    private function tiers(mixed $tiers, callable $fail): array
    {
        if (! is_array($tiers) || $tiers === [] || count($tiers) > 10) {
            $fail('config', __('Add between one and ten spend tiers.'));
        }

        $clean = [];
        foreach ((array) $tiers as $tier) {
            $min = is_array($tier) ? ($tier['min_spend'] ?? null) : null;
            $kind = is_array($tier) ? ($tier['type'] ?? null) : null;
            $value = is_array($tier) ? ($tier['value'] ?? null) : null;

            if (! is_int($min) || $min < 1 || ! in_array($kind, ['percentage', 'fixed'], true) || ! is_int($value) || $value < 1
                || ($kind === 'percentage' && $value > 10000) || isset($clean[$min])) {
                $fail('config', __('Each tier needs its own minimum spend and a positive discount (at most 100%).'));
            }

            $clean[$min] = ['min_spend' => $min, 'type' => (string) $kind, 'value' => $value];
        }

        ksort($clean);

        return array_values($clean);
    }
}
