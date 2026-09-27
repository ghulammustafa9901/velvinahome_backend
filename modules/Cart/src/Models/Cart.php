<?php

declare(strict_types=1);

namespace Modules\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int|null $customer_id
 * @property string|null $token_hash
 * @property string $currency
 * @property string|null $coupon_code
 * @property string $status
 * @property string|null $order_ulid
 * @property Carbon $expires_at
 */
final class Cart extends Model
{
    use HasUlid;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    /**
     * @return array<string, int> variant ULID => quantity
     */
    public function lines(): array
    {
        return $this->loadMissing('items')->items->mapWithKeys(fn (CartItem $item): array => [$item->variant_ulid => $item->quantity])->all();
    }
}
