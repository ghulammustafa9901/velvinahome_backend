<?php

declare(strict_types=1);

namespace Modules\Imports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Support\Casts\MoneyCast;
use Modules\Support\Concerns\HasUlid;
use Modules\Support\ValueObjects\Money;

/**
 * @property int $id
 * @property string $ulid
 * @property string $source
 * @property string $external_id
 * @property string $number
 * @property string $status
 * @property string $currency
 * @property Money $subtotal
 * @property Money $discount
 * @property Money $shipping
 * @property Money $tax
 * @property Money $total
 * @property string|null $email
 * @property int|null $customer_id
 * @property string|null $customer_name
 * @property string|null $phone
 * @property string|null $payment_method
 * @property array<string, string|null>|null $shipping_address
 * @property list<array{title: string, sku: string|null, quantity: int, total: int}> $lines
 * @property Carbon $placed_at
 * @property Carbon|null $completed_at
 */
final class ImportedOrder extends Model
{
    use HasUlid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal' => MoneyCast::class.':currency',
            'discount' => MoneyCast::class.':currency',
            'shipping' => MoneyCast::class.':currency',
            'tax' => MoneyCast::class.':currency',
            'total' => MoneyCast::class.':currency',
            'shipping_address' => 'array',
            'lines' => 'array',
            'placed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
