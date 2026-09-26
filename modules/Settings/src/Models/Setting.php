<?php

declare(strict_types=1);

namespace Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $group
 * @property string $key
 * @property mixed $value
 * @property bool $is_encrypted
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Setting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'is_encrypted' => 'boolean',
        ];
    }
}
