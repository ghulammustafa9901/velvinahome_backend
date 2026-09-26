<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Platform\Enums\DomainKind;
use Modules\Platform\Enums\ProvisioningStep;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Exceptions\TenantDatabaseNotProvisioned;
use Modules\Platform\Tenancy\TenantDatabaseConfig;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\DatabaseConfig;

/**
 * One brand: one website, one admin, one PostgreSQL database (spec §4.1).
 *
 * The id is a ULID and is what every tenant-aware subsystem keys on — cache tags, storage
 * prefixes, queue payloads, search collection names. The slug only names the database and
 * its roles, which is why it is fixed at provisioning.
 *
 * @property string $id
 * @property int $company_id
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 * @property int|null $plan_id
 * @property string $topology
 * @property string|null $schema_version
 * @property string $default_currency
 * @property string $default_timezone
 * @property string $default_locale
 * @property string $theme_id
 * @property array<string, string> $provisioning_state
 * @property string|null $health_status
 * @property Carbon|null $health_checked_at
 * @property Carbon|null $last_backup_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Company $company
 * @property-read Plan|null $plan
 * @property-read TenantDatabase|null $databaseCredentials
 */
final class Tenant extends BaseTenant implements TenantWithDatabase
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (self $tenant): void {
            if (blank($tenant->getAttribute('id'))) {
                $tenant->setAttribute('id', (string) Str::ulid());
            }
        });
    }

    /**
     * Real columns. Anything else set on the model would be folded into the `data` jsonb
     * column by stancl's virtual-column behaviour; listing every column here keeps that
     * overflow empty and the schema honest.
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id', 'company_id', 'name', 'slug', 'status', 'plan_id', 'topology', 'schema_version',
            'default_currency', 'default_timezone', 'default_locale', 'theme_id',
            'provisioning_state', 'health_status', 'health_checked_at', 'last_backup_at',
            'created_at', 'updated_at',
        ];
    }

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'provisioning_state' => 'array',
            'health_checked_at' => 'datetime',
            'last_backup_at' => 'datetime',
        ];
    }

    public function database(): DatabaseConfig
    {
        $credentials = $this->databaseCredentials;

        if ($credentials === null) {
            throw TenantDatabaseNotProvisioned::for($this);
        }

        return new TenantDatabaseConfig($this, $credentials);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class, 'tenant_id');
    }

    /**
     * @return HasOne<TenantDatabase, $this>
     */
    public function databaseCredentials(): HasOne
    {
        return $this->hasOne(TenantDatabase::class, 'tenant_id');
    }

    /**
     * @return HasMany<TenantFeatureFlag, $this>
     */
    public function featureFlags(): HasMany
    {
        return $this->hasMany(TenantFeatureFlag::class, 'tenant_id');
    }

    /**
     * @return HasMany<TenantMigrationRun, $this>
     */
    public function migrationRuns(): HasMany
    {
        return $this->hasMany(TenantMigrationRun::class, 'tenant_id');
    }

    public function primaryHost(DomainKind $kind): ?string
    {
        $domain = $this->domains
            ->first(fn (Domain $domain): bool => $domain->kind === $kind && $domain->is_primary);

        return $domain?->hostname;
    }

    public function hasCompleted(ProvisioningStep $step): bool
    {
        return isset($this->provisioning_state[$step->value]);
    }
}
