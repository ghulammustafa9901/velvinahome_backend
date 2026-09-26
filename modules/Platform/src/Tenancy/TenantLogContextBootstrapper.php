<?php

declare(strict_types=1);

namespace Modules\Platform\Tenancy;

use Illuminate\Support\Facades\Context;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Stamps the tenant id on every log line and every error report written while tenancy is
 * initialised (spec §4.4, §9). Laravel's Context is added to each log record's extra data,
 * so no call site has to remember to pass it.
 */
final class TenantLogContextBootstrapper implements TenancyBootstrapper
{
    public function bootstrap(Tenant $tenant): void
    {
        Context::add('tenant_id', (string) $tenant->getTenantKey());
    }

    public function revert(): void
    {
        Context::forget('tenant_id');
    }
}
