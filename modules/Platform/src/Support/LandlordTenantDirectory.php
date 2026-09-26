<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

use Modules\Platform\Contracts\TenantDirectory;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\Tenant;

final class LandlordTenantDirectory implements TenantDirectory
{
    public function activeTenantIds(): array
    {
        return array_map(
            fn (Tenant $tenant): string => $tenant->id,
            Tenant::listOf(Tenant::query()->where('status', TenantStatus::Active->value)->orderBy('slug')),
        );
    }

    public function runIn(string $tenantId, callable $callback): mixed
    {
        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        $previous = tenant();

        try {
            tenancy()->initialize($tenant);

            return $callback();
        } finally {
            $previous === null ? tenancy()->end() : tenancy()->initialize($previous);
        }
    }
}
