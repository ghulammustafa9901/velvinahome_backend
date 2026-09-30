<?php

declare(strict_types=1);

namespace Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $date
 * @property string $currency
 * @property string $product_ulid
 * @property string $title
 * @property int $units
 * @property int $revenue_amount
 * @property int $orders
 */
final class ProductDayReport extends Model
{
    public $timestamps = false;

    protected $table = 'report_product_days';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'units' => 'integer',
            'revenue_amount' => 'integer',
            'orders' => 'integer',
        ];
    }
}
