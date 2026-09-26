<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int $variant_id
 * @property int $location_id
 * @property int $quantity
 * @property string $reference_type
 * @property string $reference_ulid
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon|null $converted_at
 * @property Carbon|null $released_at
 */
final class StockReservation extends Model
{
    use HasUlid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expires_at' => 'datetime',
            'converted_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
