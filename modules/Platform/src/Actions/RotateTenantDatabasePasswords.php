<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Platform\Models\PlatformUser;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Support\AuthorizesPlatformActor;
use Modules\Platform\Support\PlatformAudit;
use Modules\Platform\Support\Provisioning\PostgresProvisioner;
use Modules\Platform\Tenancy\TenantResolver;
use Modules\Support\Contracts\Action;
use RuntimeException;

/**
 * `tenant:rotate-db-passwords`: new passwords for one brand's two database roles
 * (docs/runbooks/rotate-secrets.md).
 *
 * The landlord record is written in a transaction that commits only after both roles have
 * their new password, so the record never names a password the database does not have. If a
 * role change fails half-way, run it again: both roles get fresh passwords. Open sessions keep
 * working; the next connection uses the new password.
 */
final class RotateTenantDatabasePasswords implements Action
{
    use AuthorizesPlatformActor;

    public function __construct(
        private readonly PostgresProvisioner $provisioner,
        private readonly PlatformAudit $audit,
        private readonly TenantResolver $resolver,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(Tenant $tenant, ?PlatformUser $actor = null): void
    {
        $this->authorizeTenantManagement($actor);

        $credentials = $tenant->databaseCredentials()->first()
            ?? throw new RuntimeException("Tenant {$tenant->slug} has no database credentials.");

        $appPassword = bin2hex(random_bytes(24));
        $migrationPassword = bin2hex(random_bytes(24));

        $this->db->connection('landlord')->transaction(function () use ($credentials, $appPassword, $migrationPassword): void {
            $credentials->forceFill(['app_password' => $appPassword, 'migration_password' => $migrationPassword])->save();
            $this->provisioner->changePassword($credentials->migration_username, $migrationPassword);
            $this->provisioner->changePassword($credentials->app_username, $appPassword);
        });

        // Model events already forgot it inside the transaction; forget again now it is
        // committed, so no request in between can have re-cached the old record.
        $this->resolver->forgetTenant($tenant->id);

        $this->audit->record('tenant.db_passwords_rotated', $actor, $tenant->id, 'tenant', $tenant->id, [
            'roles' => [$credentials->app_username, $credentials->migration_username],
        ]);
    }
}
