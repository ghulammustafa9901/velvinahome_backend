<?php

declare(strict_types=1);

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Support\Concerns\HasUlid;

/**
 * Staff-only note on an order; never shown to the customer.
 *
 * @property int $id
 * @property string $ulid
 * @property int $order_id
 * @property string|null $author_ulid
 * @property string $body
 * @property Carbon $created_at
 */
final class OrderNote extends Model
{
    use HasUlid;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
