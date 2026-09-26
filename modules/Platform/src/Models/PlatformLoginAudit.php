<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $email_attempted
 * @property int|null $platform_user_id
 * @property bool $successful
 * @property string|null $failure_reason
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
final class PlatformLoginAudit extends LandlordModel
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
        ];
    }
}
