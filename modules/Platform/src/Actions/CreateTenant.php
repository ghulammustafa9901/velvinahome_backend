<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Storage;
use Modules\Identity\Contracts\StaffDirectory;
use Modules\Identity\Data\OwnerAccountData;
use Modules\Platform\Data\ProvisioningResult;
use Modules\Platform\Data\TenantData;
use Modules\Platform\Enums\DomainKind;
use Modules\Platform\Enums\ProvisioningStep;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Exceptions\ProvisioningFailed;
use Modules\Platform\Exceptions\TenantLifecycleViolation;
use Modules\Platform\Models\Company;
use Modules\Platform\Models\CompanyOwnerBrandLink;
use Modules\Platform\Models\CompanyOwnerIdentity;
use Modules\Platform\Models\Domain;
use Modules\Platform\Models\Plan;
use Modules\Platform\Models\PlatformUser;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantDatabase;
use Modules\Platform\Support\AuthorizesPlatformActor;
use Modules\Platform\Support\PlatformAudit;
use Modules\Platform\Support\Provisioning\PostgresProvisioner;
use Modules\Platform\Support\Provisioning\TenantDatabaseNames;
use Modules\Support\Contracts\Action;
use RuntimeException;
use Throwable;

/**
 * `tenant:create` — takes a brand from nothing to a working, isolated store (spec §4.6).
 *
 * Built as ordered steps (ProvisioningStep), each recorded on the tenant as it completes.
 * Re-running after a failure skips what is done and resumes at the failed step, so the
 * command is idempotent and safe to repeat; nothing is ever created twice.
 *
 * The Artisan command and the platform panel both call this Action, so they are the same
 * behaviour by construction (spec §4.9).
 */
final class CreateTenant implements Action
{
    use AuthorizesPlatformActor;

    public function __construct(
        private readonly PostgresProvisioner $provisioner,
        private readonly MigrateTenant $migrateTenant,
        private readonly SeedTenant $seedTenant,
        private readonly StaffDirectory $staff,
        private readonly PlatformAudit $audit,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(TenantData $data, ?PlatformUser $actor = null): ProvisioningResult
    {
        $this->authorizeTenantManagement($actor);

        $tenant = $this->recordTenant($data);
        $result = new ProvisioningResult($tenant);

        foreach (ProvisioningStep::cases() as $step) {
            if ($tenant->hasCompleted($step)) {
                $result->alreadyComplete[] = $step;

                continue;
            }

            try {
                $this->runStep($step, $tenant, $data, $result);
            } catch (Throwable $exception) {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }

                $this->audit->record('tenant.provisioning_failed', $actor, $tenant->id, properties: [
                    'step' => $step->value,
                    'error' => mb_substr($exception->getMessage(), 0, 500),
                ]);

                throw ProvisioningFailed::atStep($tenant->slug, $step, $exception);
            }

            $tenant->forceFill([
                'provisioning_state' => [...$tenant->provisioning_state, $step->value => now()->toIso8601String()],
            ])->save();

            $result->completedNow[] = $step;
        }

        if ($result->completedNow !== []) {
            $this->audit->record('tenant.provisioned', $actor, $tenant->id, 'tenant', $tenant->id, [
                'steps' => array_map(fn (ProvisioningStep $step): string => $step->value, $result->completedNow),
            ]);
        }

        return $result;
    }

    /**
     * The landlord record and its domains. Re-running with the same slug resumes the same
     * tenant; a fully provisioned tenant is never silently re-created.
     */
    private function recordTenant(TenantData $data): Tenant
    {
        $company = Company::query()->where('slug', $data->company)->firstOrFail();

        $existing = Tenant::query()->where('slug', $data->slug)->first();

        if ($existing !== null) {
            if ($existing->status !== TenantStatus::Provisioning) {
                throw TenantLifecycleViolation::slugTaken($data->slug);
            }

            if ($existing->company_id !== $company->id) {
                throw TenantLifecycleViolation::companyMismatch($data->slug);
            }

            return $existing;
        }

        $hosts = [
            DomainKind::Storefront->value => strtolower($data->storefrontHost),
            DomainKind::Admin->value => strtolower($data->adminHost),
            DomainKind::Api->value => strtolower($data->apiHost),
        ];

        foreach ($hosts as $hostname) {
            if ($hostname === strtolower((string) config('platform.host')) || Domain::query()->where('hostname', $hostname)->exists()) {
                throw TenantLifecycleViolation::hostTaken($hostname);
            }
        }

        return $this->db->connection('landlord')->transaction(function () use ($data, $company, $hosts): Tenant {
            $plan = $data->plan === null ? null : Plan::query()->where('key', $data->plan)->firstOrFail();

            $tenant = Tenant::query()->create([
                'company_id' => $company->id,
                'name' => $data->name,
                'slug' => $data->slug,
                'status' => TenantStatus::Provisioning,
                'plan_id' => $plan?->id,
                'topology' => (string) config('platform.topology'),
                'default_currency' => strtoupper($data->currency),
                'default_timezone' => $data->timezone,
                'default_locale' => $data->locale,
                'theme_id' => $data->themeId,
                'provisioning_state' => [],
            ]);

            foreach ($hosts as $kind => $hostname) {
                $tenant->domains()->create([
                    'hostname' => $hostname,
                    'kind' => $kind,
                    'is_primary' => true,
                ]);
            }

            return $tenant;
        });
    }

    private function runStep(ProvisioningStep $step, Tenant $tenant, TenantData $data, ProvisioningResult $result): void
    {
        match ($step) {
            ProvisioningStep::Database => $this->createDatabase($tenant),
            ProvisioningStep::Migrations => $this->migrate($tenant),
            ProvisioningStep::Seed => $this->seedTenant->handle($tenant),
            // Typesense collections arrive with the Search work in Phase 2; the step exists
            // now so every tenant's provisioning record has the same shape.
            ProvisioningStep::SearchCollections => null,
            ProvisioningStep::StoragePrefix => $this->prepareStorage($tenant),
            ProvisioningStep::OwnerAccount => $result->owner = $this->createOwner($tenant, $data),
            ProvisioningStep::Activate => $tenant->forceFill(['status' => TenantStatus::Active])->save(),
        };
    }

    private function createDatabase(Tenant $tenant): void
    {
        $names = TenantDatabaseNames::forSlug($tenant->slug);
        $server = [
            'host' => (string) config('database.connections.tenant_template.host'),
            'port' => (int) config('database.connections.tenant_template.port'),
        ];

        // Credentials are stored (encrypted) before the roles exist, so a failure between
        // the two steps is resumed with the same passwords rather than orphaning roles.
        $credentials = $tenant->databaseCredentials ?? TenantDatabase::query()->create([
            'tenant_id' => $tenant->id,
            'host' => $server['host'],
            'port' => $server['port'],
            'database' => $names->database,
            'app_username' => $names->appRole,
            'app_password' => bin2hex(random_bytes(24)),
            'migration_username' => $names->migratorRole,
            'migration_password' => bin2hex(random_bytes(24)),
        ]);

        $tenant->setRelation('databaseCredentials', $credentials);

        $this->provisioner->createDatabase(
            $names,
            $credentials->app_password,
            $credentials->migration_password,
            ['host' => $credentials->host, 'port' => $credentials->port],
        );
    }

    private function migrate(Tenant $tenant): void
    {
        $run = $this->migrateTenant->handle($tenant);

        if ($run->status !== 'succeeded') {
            throw new RuntimeException('Tenant migrations failed: '.$run->error);
        }
    }

    private function prepareStorage(Tenant $tenant): void
    {
        $tenant->run(function () use ($tenant): void {
            foreach (['tenant_media', 'tenant_private'] as $disk) {
                Storage::disk($disk)->put('.tenant', $tenant->id);
            }
        });
    }

    private function createOwner(Tenant $tenant, TenantData $data): OwnerAccountData
    {
        /** @var OwnerAccountData $owner */
        $owner = $tenant->run(fn (): OwnerAccountData => $this->staff->ensureOwner($data->ownerName, $data->ownerEmail));

        if ($data->ownerIsCompanyOwner) {
            $identity = CompanyOwnerIdentity::query()->firstOrCreate(
                ['email' => strtolower($data->ownerEmail)],
                ['company_id' => $tenant->company_id, 'name' => $data->ownerName],
            );

            // One identity never spans companies: an email already owning another company's
            // brands is not silently extended to this one.
            if ($identity->company_id === $tenant->company_id) {
                CompanyOwnerBrandLink::query()->updateOrCreate(
                    ['company_owner_identity_id' => $identity->id, 'tenant_id' => $tenant->id],
                    ['tenant_staff_user_ulid' => $owner->ulid, 'granted_at' => now(), 'revoked_at' => null],
                );
            }
        }

        return $owner;
    }
}
