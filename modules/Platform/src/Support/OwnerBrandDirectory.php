<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

use Modules\Platform\Contracts\OwnerBrands;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\CompanyOwnerBrandLink;
use Modules\Platform\Models\Tenant;

final class OwnerBrandDirectory implements OwnerBrands
{
    public function otherBrandsFor(string $staffUlid): array
    {
        $current = tenant();

        if (! $current instanceof Tenant) {
            return [];
        }

        $identityId = CompanyOwnerBrandLink::query()
            ->where('tenant_id', $current->id)
            ->where('tenant_staff_user_ulid', $staffUlid)
            ->whereNull('revoked_at')
            ->value('company_owner_identity_id');

        if ($identityId === null) {
            return [];
        }

        $tenantIds = CompanyOwnerBrandLink::query()
            ->where('company_owner_identity_id', $identityId)
            ->where('tenant_id', '!=', $current->id)
            ->whereNull('revoked_at')
            ->pluck('tenant_id');

        $brands = [];

        foreach (Tenant::listOf(Tenant::query()->whereKey($tenantIds)->where('status', TenantStatus::Active->value)->orderBy('name')) as $tenant) {
            $brands[] = ['id' => $tenant->id, 'name' => $tenant->name];
        }

        return $brands;
    }
}
