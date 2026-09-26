<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Support\Carbon;

/**
 * Append-only platform audit trail. The landlord application role cannot update or delete
 * these rows; only inserts reach the database (spec §6.3, §9).
 *
 * @property int $id
 * @property int|null $platform_user_id
 * @property string $event
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $tenant_id
 * @property array<string, mixed> $properties
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
final class PlatformActivity extends LandlordModel
{
    public const UPDATED_AT = null;

    protected $table = 'platform_activity_log';

    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }
}
