<?php

declare(strict_types=1);

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $order_return_id
 * @property int $order_item_id
 * @property int $quantity
 */
final class OrderReturnItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];
}
