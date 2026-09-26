<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Inventory\Enums\MovementType;
use Modules\Support\Concerns\HasUlid;

/**
 * Append-only. The application role cannot update or delete these rows; a correction is a
 * new, opposite movement with a reason.
 *
 * @property int $id
 * @property string $ulid
 * @property int $variant_id
 * @property int $location_id
 * @property MovementType $type
 * @property int $quantity
 * @property int $balance_after
 * @property int|null $unit_cost_amount
 * @property string|null $unit_cost_currency
 * @property string|null $reference_type
 * @property string|null $reference_ulid
 * @property string|null $reason
 * @property string|null $actor_ulid
 * @property Carbon $created_at
 * @property-read Location $location
 */
final class InventoryMovement extends Model
{
    use HasUlid;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'quantity' => 'integer',
            'balance_after' => 'integer',
            'unit_cost_amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
