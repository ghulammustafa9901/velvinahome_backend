<?php

declare(strict_types=1);

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Support\Concerns\HasUlid;

/**
 * Goods a customer sent back after delivery.
 *
 * @property int $id
 * @property string $ulid
 * @property int $order_id
 * @property string $reason
 * @property bool $restocked
 * @property string|null $actor_ulid
 */
final class OrderReturn extends Model
{
    use HasUlid;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['restocked' => 'boolean'];
    }

    /**
     * @return HasMany<OrderReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class);
    }
}
