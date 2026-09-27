<?php

declare(strict_types=1);

namespace Modules\Tax\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property int $rate_bps
 * @property string|null $country_code
 * @property string|null $province
 * @property bool $is_active
 */
final class TaxRate extends Model
{
    use HasUlid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate_bps' => 'integer', 'is_active' => 'boolean'];
    }
}
