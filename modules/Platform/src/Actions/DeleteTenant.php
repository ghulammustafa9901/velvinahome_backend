<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Exceptions\TenantLifecycleViolation;
use Modules\Platform\Models\PlatformUser;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantBackup;
use Modules\Platform\Support\AuthorizesPlatformActor;
use Modules\Platform\Support\PlatformAudit;
use Modules\Platform\Support\Provisioning\PostgresProvisioner;
use Modules\Platform\Support\Provisioning\TenantDatabaseNames;
use Modules\Support\Contracts\Action;

/**
 * `tenant:delete` — only with explicit confirmation AND a fresh, successful backup (spec
 * §4.6). The database and its roles are dropped; the landlord record is kept as `archived`
 * so the audit trail, backups and migration history still point at something.
 */
final class DeleteTenant implements Action
{
    use AuthorizesPlatformActor;

    public const BACKUP_MAX_AGE_MINUTES = 60;

    public function __construct(
        private readonly PostgresProvisioner $provisioner,
        private readonly PlatformAudit $audit,
    ) {}

    public function handle(Tenant $tenant, string $confirmation, ?PlatformUser $actor = null): Tenant
    {
        $this->authorizeTenantManagement($actor);

        if ($confirmation !== $tenant->slug) {
            throw TenantLifecycleViolation::deletionNotConfirmed($tenant->slug);
        }

        $freshBackup = TenantBackup::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'succeeded')
            ->where('finished_at', '>=', now()->subMinutes(self::BACKUP_MAX_AGE_MINUTES))
            ->exists();

        if (! $freshBackup) {
            throw TenantLifecycleViolation::noFreshBackup($tenant->slug, self::BACKUP_MAX_AGE_MINUTES);
        }

        $this->provisioner->dropDatabase(TenantDatabaseNames::forSlug($tenant->slug));

        $tenant->databaseCredentials()->delete();
        $tenant->domains()->delete();
        $tenant->forceFill(['status' => TenantStatus::Archived])->save();

        $this->audit->record('tenant.deleted', $actor, $tenant->id, 'tenant', $tenant->id);

        return $tenant;
    }
}
