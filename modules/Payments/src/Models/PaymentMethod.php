<?php

declare(strict_types=1);

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $code
 * @property string $label
 * @property string|null $description
 * @property bool $is_enabled
 * @property array<string, mixed> $config
 * @property array<string, mixed>|null $credentials
 * @property int $position
 */
final class PaymentMethod extends Model
{
    use HasUlid;

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'config' => 'array', 'credentials' => 'encrypted:array'];
    }
}
