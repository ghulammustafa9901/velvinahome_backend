<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $code
 * @property string $type
 * @property array<string, mixed>|null $address
 * @property bool $is_default
 * @property bool $is_active
 * @property int $priority
 */
final class Location extends Model
{
    use HasUlid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['address' => 'array', 'is_default' => 'boolean', 'is_active' => 'boolean', 'priority' => 'integer'];
    }
}
