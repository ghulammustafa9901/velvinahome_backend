<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $token_hash
 * @property int $company_owner_identity_id
 * @property string $from_tenant_id
 * @property string $to_tenant_id
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon $created_at
 */
final class BrandSwitchToken extends LandlordModel
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
