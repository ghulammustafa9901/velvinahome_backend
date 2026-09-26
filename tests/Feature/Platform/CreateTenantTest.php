<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Identity\Models\StaffUser;
use Modules\Platform\Actions\CreateTenant;
use Modules\Platform\Enums\ProvisioningStep;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Exceptions\TenantLifecycleViolation;
use Modules\Platform\Models\CompanyOwnerBrandLink;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Support\Provisioning\PostgresProvisioner;
use Modules\Platform\Support\Provisioning\TenantDatabaseNames;
use Modules\Settings\Contracts\TenantSettings;
use Spatie\Permission\Models\Role;
use Tests\Support\TestPlatform;

/*
| Spec §4.6: tenant:create produces a working tenant — database, roles, schema, seeds,
| storage prefix, owner account — and is idempotent and safe to re-run.
*/

afterEach(function (): void {
    if (isset($this->slug) && ($tenant = Tenant::query()->where('slug', $this->slug)->first()) !== null) {
        TestPlatform::destroy($tenant);
    }
});

it('provisions a complete, working tenant', function (): void {
    $this->slug = TestPlatform::temporarySlug();

    $result = app(CreateTenant::class)->handle(TestPlatform::tenantData($this->slug, 'fixture-x'));
    $tenant = $result->tenant->refresh();
    $names = TenantDatabaseNames::forSlug($this->slug);
    $provisioner = app(PostgresProvisioner::class);

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($result->completedNow)->toBe(ProvisioningStep::cases())
        ->and($provisioner->databaseExists($names->database))->toBeTrue()
        ->and($provisioner->roleExists($names->appRole))->toBeTrue()
        ->and($provisioner->roleExists($names->migratorRole))->toBeTrue()
        ->and($tenant->domains)->toHaveCount(3)
        ->and($tenant->schema_version)->not->toBeNull()
        ->and($result->owner?->temporaryPassword)->toBeString();

    $tenant->run(function () use ($result): void {
        $owner = StaffUser::query()->where('ulid', $result->owner?->ulid)->firstOrFail();

        expect($owner->hasRole('Owner'))->toBeTrue()
            ->and(Role::query()->count())->toBe(6)
            ->and(app(TenantSettings::class)->string('store.currency'))->toBe('PKR')
            ->and(Storage::disk('tenant_media')->exists('.tenant'))->toBeTrue();
    });

    expect(CompanyOwnerBrandLink::query()->where('tenant_id', $tenant->id)->exists())->toBeTrue();
});

it('resumes after a failure without duplicating anything', function (): void {
    $this->slug = TestPlatform::temporarySlug();
    $data = TestPlatform::tenantData($this->slug, 'fixture-x');

    $first = app(CreateTenant::class)->handle($data);

    // Simulate a crash after the database step: every later step is "not done yet".
    $first->tenant->refresh()->forceFill([
        'status' => TenantStatus::Provisioning,
        'provisioning_state' => [ProvisioningStep::Database->value => now()->toIso8601String()],
    ])->save();

    $second = app(CreateTenant::class)->handle($data);

    expect($second->alreadyComplete)->toBe([ProvisioningStep::Database])
        ->and($second->tenant->refresh()->status)->toBe(TenantStatus::Active)
        ->and($second->owner?->created)->toBeFalse();

    $second->tenant->run(function (): void {
        expect(StaffUser::query()->count())->toBe(1)
            ->and(Role::query()->count())->toBe(6)
            ->and(DB::table('settings')->where('group', 'store')->where('key', 'name')->count())->toBe(1);
    });
});

it('refuses to re-create a tenant that is already live', function (): void {
    app(CreateTenant::class)->handle(TestPlatform::tenantData(TestPlatform::BRAND_A, 'fixture-x'));
})->throws(TenantLifecycleViolation::class);

it('refuses a hostname that belongs to another tenant', function (): void {
    $this->slug = TestPlatform::temporarySlug();
    $data = TestPlatform::tenantData($this->slug, 'fixture-x');

    app(CreateTenant::class)->handle(new Modules\Platform\Data\TenantData(
        company: $data->company,
        name: $data->name,
        slug: $data->slug,
        storefrontHost: $data->storefrontHost,
        adminHost: TestPlatform::adminHost(TestPlatform::BRAND_B),
        apiHost: $data->apiHost,
        ownerName: $data->ownerName,
        ownerEmail: $data->ownerEmail,
    ));
})->throws(TenantLifecycleViolation::class);
