<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Support\Casts\MoneyCast;
use Modules\Support\ValueObjects\Money;

/**
 * @property int $id
 * @property int $shipping_method_id
 * @property string $strategy
 * @property int|null $min_value
 * @property int|null $max_value
 * @property Money $amount
 * @property int|null $free_over_amount
 */
final class ShippingRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'min_value' => 'integer',
            'max_value' => 'integer',
            'free_over_amount' => 'integer',
        ];
    }

    public function applies(int $weightGrams, Money $subtotal): bool
    {
        $value = match ($this->strategy) {
            'weight' => $weightGrams,
            'order_value' => $subtotal->amount,
            default => null,
        };

        return $value === null
            || (($this->min_value === null || $value >= $this->min_value) && ($this->max_value === null || $value <= $this->max_value));
    }
}
