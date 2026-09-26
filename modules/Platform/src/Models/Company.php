<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Support\Concerns\HasUlid;

/**
 * The client business: the contracting and billing party. Groups brands; holds no brand
 * data (spec §1.2, §4.1a).
 *
 * @property int $id
 * @property string $ulid
 * @property string $legal_name
 * @property string $display_name
 * @property string $slug
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string $country_code
 * @property string $contract_status
 * @property string|null $billing_notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Company extends LandlordModel
{
    use HasUlid;

    /**
     * @return HasMany<Tenant, $this>
     */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    /**
     * @return HasMany<CompanyOwnerIdentity, $this>
     */
    public function ownerIdentities(): HasMany
    {
        return $this->hasMany(CompanyOwnerIdentity::class);
    }
}
