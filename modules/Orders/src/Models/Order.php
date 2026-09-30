<?php

declare(strict_types=1);

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\Customers\Data\AddressData;
use Modules\Orders\States\OrderState;
use Modules\Support\Casts\MoneyCast;
use Modules\Support\Concerns\HasUlid;
use Modules\Support\ValueObjects\Money;
use Spatie\ModelStates\HasStates;

/**
 * @property int $id
 * @property string $ulid
 * @property string $number
 * @property int|null $customer_id
 * @property string $email
 * @property string $phone
 * @property OrderState $status
 * @property string $payment_status
 * @property string $payment_method_code
 * @property string|null $shipping_method_code
 * @property string|null $shipping_method_name
 * @property string $currency
 * @property Money $subtotal
 * @property Money $discount
 * @property Money $shipping
 * @property Money $tax
 * @property Money $total
 * @property bool $tax_inclusive
 * @property string|null $coupon_code
 * @property list<array{name: string, amount: int}> $promotions
 * @property string|null $customer_note
 * @property string|null $cod_confirmation
 * @property int $cod_attempts
 * @property string|null $cancel_reason
 * @property string $access_token_hash
 * @property string $idempotency_key
 * @property string $request_hash
 * @property Carbon $placed_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $fulfilled_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OrderItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OrderStateTransition> $transitions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Shipment> $shipments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OrderNote> $notes
 * @property-read OrderAddress|null $shippingAddress
 */
final class Order extends Model
{
    use HasStates;
    use HasUlid;

    protected $guarded = ['id'];

    protected $hidden = ['access_token_hash', 'request_hash'];

    protected function casts(): array
    {
        return [
            'status' => OrderState::class,
            'subtotal' => MoneyCast::class.':currency',
            'discount' => MoneyCast::class.':currency',
            'shipping' => MoneyCast::class.':currency',
            'tax' => MoneyCast::class.':currency',
            'total' => MoneyCast::class.':currency',
            'tax_inclusive' => 'boolean',
            'promotions' => 'array',
            'cod_attempts' => 'integer',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderStateTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(OrderStateTransition::class)->orderBy('id');
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class)->orderByDesc('id');
    }

    /**
     * @return HasOne<OrderAddress, $this>
     */
    public function shippingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', 'shipping');
    }

    public function shippingAddressData(): ?AddressData
    {
        $address = $this->shippingAddress;

        return $address === null ? null : AddressData::fromColumns($address->getAttributes());
    }

    public function isCod(): bool
    {
        return $this->payment_method_code === 'cod';
    }
}
