<?php

declare(strict_types=1);

namespace Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $date
 * @property string $currency
 * @property int $orders_placed
 * @property int $orders_cancelled
 * @property int $orders_returned
 * @property int $units
 * @property int $subtotal_amount
 * @property int $discount_amount
 * @property int $shipping_amount
 * @property int $tax_amount
 * @property int $total_amount
 * @property int $placed_total_amount
 * @property int $refunded_amount
 * @property int $refunds
 * @property int $cod_orders
 * @property int $cod_confirmed
 * @property int $cod_unreachable
 * @property int $cod_fake
 * @property int $cod_returned
 * @property array<string, array{orders: int, total: int}> $payment_methods
 * @property Carbon $rebuilt_at
 */
final class SalesDayReport extends Model
{
    public const COUNTERS = [
        'orders_placed', 'orders_cancelled', 'orders_returned', 'units', 'subtotal_amount', 'discount_amount',
        'shipping_amount', 'tax_amount', 'total_amount', 'placed_total_amount', 'refunded_amount', 'refunds',
        'cod_orders', 'cod_confirmed', 'cod_unreachable', 'cod_fake', 'cod_returned',
    ];

    public $timestamps = false;

    protected $table = 'report_sales_days';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return array_fill_keys(self::COUNTERS, 'integer') + [
            'date' => 'date',
            'payment_methods' => 'array',
            'rebuilt_at' => 'datetime',
        ];
    }
}
