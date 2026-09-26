<?php

declare(strict_types=1);

namespace Modules\Platform\Tenancy;

use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\DatabaseManager;

/**
 * Switches the default connection to the tenant's database under its application role.
 *
 * Replaces stancl's DatabaseTenancyBootstrapper only to drop its local-environment
 * "does the database exist" probe, which connects through the template connection with no
 * credentials of its own. Connecting to the tenant fails loudly by itself if it does not.
 */
final class TenantDatabaseBootstrapper implements TenancyBootstrapper
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function bootstrap(Tenant $tenant): void
    {
        /** @var TenantWithDatabase $tenant */
        $this->database->connectToTenant($tenant);
    }

    public function revert(): void
    {
        $this->database->reconnectToCentral();
    }
}
