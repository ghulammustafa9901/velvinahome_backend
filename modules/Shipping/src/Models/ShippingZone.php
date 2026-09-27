<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property list<string> $countries
 * @property list<string> $provinces
 * @property list<string> $cities
 * @property bool $is_active
 */
final class ShippingZone extends Model
{
    use HasUlid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['countries' => 'array', 'provinces' => 'array', 'cities' => 'array', 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<ShippingMethod, $this>
     */
    public function methods(): HasMany
    {
        return $this->hasMany(ShippingMethod::class)->orderBy('position');
    }

    /**
     * 0 = does not match; otherwise higher is more specific.
     */
    public function specificityFor(string $country, ?string $province, string $city): int
    {
        $contains = fn (array $list, ?string $value): bool => $value !== null
            && in_array(mb_strtolower(trim($value)), array_map(fn (string $item): string => mb_strtolower(trim($item)), $list), true);

        foreach ([[$this->countries, $country], [$this->provinces, $province], [$this->cities, $city]] as [$list, $value]) {
            if ($list !== [] && ! $contains($list, $value)) {
                return 0;
            }
        }

        return 1 + ($this->countries !== [] ? 1 : 0) + ($this->provinces !== [] ? 2 : 0) + ($this->cities !== [] ? 4 : 0);
    }
}
