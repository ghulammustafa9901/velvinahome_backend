<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

use Modules\Platform\Contracts\TenantDirectory;
use Modules\Platform\Enums\DomainKind;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\Domain;
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

    public function storefrontUrl(): ?string
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $host = Domain::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', DomainKind::Storefront->value)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->value('hostname');

        if (! is_string($host)) {
            return null;
        }

        // Local development hosts are served over plain HTTP.
        $local = str_ends_with($host, '.localhost') || str_ends_with($host, '.test');

        return ($local ? 'http://' : 'https://').$host;
    }
}
