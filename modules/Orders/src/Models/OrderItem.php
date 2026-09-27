<?php

declare(strict_types=1);

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Casts\MoneyCast;
use Modules\Support\Concerns\HasUlid;
use Modules\Support\ValueObjects\Money;

/**
 * A snapshot of what was bought, at the price it was bought for.
 *
 * @property int $id
 * @property string $ulid
 * @property int $order_id
 * @property string $variant_ulid
 * @property string $product_ulid
 * @property string $sku
 * @property string $title
 * @property string|null $variant_title
 * @property array<string, string> $options
 * @property string|null $image_url
 * @property int $quantity
 * @property string $currency
 * @property Money $unit_price
 * @property Money $subtotal
 * @property Money $discount
 * @property Money $tax
 * @property Money $total
 * @property int $tax_rate_bps
 * @property string|null $reservation_ulid
 * @property int $quantity_fulfilled
 * @property int $quantity_returned
 * @property-read Order $order
 */
final class OrderItem extends Model
{
    use HasUlid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'unit_price' => MoneyCast::class.':currency',
            'subtotal' => MoneyCast::class.':currency',
            'discount' => MoneyCast::class.':currency',
            'tax' => MoneyCast::class.':currency',
            'total' => MoneyCast::class.':currency',
            'quantity' => 'integer',
            'quantity_fulfilled' => 'integer',
            'quantity_returned' => 'integer',
            'tax_rate_bps' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function unfulfilled(): int
    {
        return $this->quantity - $this->quantity_fulfilled;
    }
}
