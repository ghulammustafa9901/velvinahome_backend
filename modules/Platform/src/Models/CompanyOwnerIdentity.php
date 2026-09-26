<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Support\Concerns\HasUlid;

/**
 * A company owner who may move between the brands of their company (spec §4.1a).
 *
 * Deliberately holds no credentials: the owner signs in to a brand's admin with that brand's
 * own staff account (and its mandatory 2FA). This identity only records which brands the
 * owner may hop to, so no password or permission ever crosses into the landlord database.
 *
 * @property int $id
 * @property string $ulid
 * @property int $company_id
 * @property string $name
 * @property string $email
 * @property string $status
 * @property Carbon|null $last_login_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Company $company
 */
final class CompanyOwnerIdentity extends LandlordModel
{
    use HasUlid;

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<CompanyOwnerBrandLink, $this>
     */
    public function brandLinks(): HasMany
    {
        return $this->hasMany(CompanyOwnerBrandLink::class);
    }
}
