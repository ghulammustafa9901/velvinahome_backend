<?php

declare(strict_types=1);

namespace Modules\Platform\Exceptions;

use Modules\Platform\Models\Tenant;
use RuntimeException;

final class TenantDatabaseNotProvisioned extends RuntimeException
{
    public static function for(Tenant $tenant): self
    {
        return new self(sprintf(
            'Tenant [%s] has no database yet. Run `php artisan tenant:create` again to resume provisioning.',
            $tenant->slug,
        ));
    }
}
