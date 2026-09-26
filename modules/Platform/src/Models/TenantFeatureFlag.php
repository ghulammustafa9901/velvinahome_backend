<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $key
 * @property bool $enabled
 * @property array<string, mixed>|null $value
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class TenantFeatureFlag extends LandlordModel
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'value' => 'array',
        ];
    }
}
