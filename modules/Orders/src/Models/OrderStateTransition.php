<?php

declare(strict_types=1);

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only order timeline.
 *
 * @property int $id
 * @property int $order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $reason
 * @property string $actor_type
 * @property string|null $actor_ulid
 * @property Carbon $created_at
 */
final class OrderStateTransition extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
