<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The running balance for one variant at one location. `on_hand` is written only by the
 * Ledger, in the same transaction as the movement that explains it.
 *
 * @property int $id
 * @property int $variant_id
 * @property int $location_id
 * @property int $on_hand
 * @property int $reserved
 * @property int $incoming
 * @property int|null $low_stock_threshold
 * @property int|null $reorder_point
 * @property int|null $reorder_qty
 * @property-read Location $location
 */
final class StockLevel extends Model
{
    public const CREATED_AT = null;

    protected $guarded = ['id', 'on_hand', 'reserved'];

    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'reserved' => 'integer',
            'incoming' => 'integer',
            'low_stock_threshold' => 'integer',
            'reorder_point' => 'integer',
            'reorder_qty' => 'integer',
        ];
    }

    public function available(): int
    {
        return $this->on_hand - $this->reserved;
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
