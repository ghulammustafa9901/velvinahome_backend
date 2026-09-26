<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_owner_identity_id
 * @property string $tenant_id
 * @property string $tenant_staff_user_ulid
 * @property int|null $granted_by_platform_user_id
 * @property Carbon $granted_at
 * @property Carbon|null $revoked_at
 * @property-read CompanyOwnerIdentity $identity
 * @property-read Tenant $tenant
 */
final class CompanyOwnerBrandLink extends LandlordModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CompanyOwnerIdentity, $this>
     */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(CompanyOwnerIdentity::class, 'company_owner_identity_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
