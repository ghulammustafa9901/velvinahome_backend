<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $adapter
 * @property string|null $tracking_url_template
 * @property array<string, string>|null $credentials
 * @property bool $is_enabled
 * @property int $position
 */
final class Courier extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'is_enabled' => 'boolean', 'position' => 'integer'];
    }
}
