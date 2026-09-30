<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Support\Concerns\HasUlid;

/**
 * An automatic promotion (spec §6.4): applied by the pricing pipeline without a code.
 *
 *  - percentage_sale: config {percent_bps} off every eligible line
 *  - spend_tier: config {tiers: [{min_spend, type: percentage|fixed, value}]} — the highest tier
 *    the eligible spend reaches
 *  - buy_x_get_y: config {buy, get, percent_bps} — in every group of buy+get eligible units,
 *    the `get` cheapest are discounted by percent_bps (10000 = free)
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $type
 * @property bool $is_active
 * @property int $priority
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property array{product_ulids?: list<string>, category_ulids?: list<string>} $conditions
 * @property array<string, mixed> $config
 */
final class Promotion extends Model
{
    use HasUlid;

    public const TYPES = ['percentage_sale', 'spend_tier', 'buy_x_get_y'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'conditions' => 'array',
            'config' => 'array',
        ];
    }

    /**
     * @param  Builder<Promotion>  $query
     * @return Builder<Promotion>
     */
    public function scopeRunning(Builder $query): Builder
    {
        $now = Carbon::now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderBy('priority')->orderBy('id');
    }
}
