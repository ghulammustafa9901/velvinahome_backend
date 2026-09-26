<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property array<string, bool> $default_features
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Plan extends LandlordModel
{
    protected function casts(): array
    {
        return [
            'default_features' => 'array',
        ];
    }
}
