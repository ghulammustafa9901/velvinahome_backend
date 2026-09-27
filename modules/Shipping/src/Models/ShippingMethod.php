<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int $shipping_zone_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_cod_allowed
 * @property bool $is_active
 * @property int|null $min_days
 * @property int|null $max_days
 * @property-read ShippingZone $zone
 */
final class ShippingMethod extends Model
{
    use HasUlid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_cod_allowed' => 'boolean', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<ShippingZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    /**
     * @return HasMany<ShippingRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class)->orderBy('position');
    }
}
