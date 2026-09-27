<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $code
 * @property string $discount_type
 * @property int $value
 * @property int|null $minimum_spend_amount
 * @property int|null $usage_limit
 * @property int|null $usage_limit_per_customer
 * @property int $used_count
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property array{product_ulids?: list<string>, category_ulids?: list<string>} $conditions
 * @property bool $is_active
 */
final class Coupon extends Model
{
    use HasUlid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'minimum_spend_amount' => 'integer',
            'usage_limit' => 'integer',
            'usage_limit_per_customer' => 'integer',
            'used_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'conditions' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
