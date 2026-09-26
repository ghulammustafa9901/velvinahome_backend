<?php

declare(strict_types=1);

use Modules\Platform\Actions\CreateTenant;
use Modules\Platform\Actions\DeleteTenant;
use Modules\Platform\Actions\ExportTenant;
use Modules\Platform\Enums\PlatformRole;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Exceptions\TenantLifecycleViolation;
use Modules\Platform\Models\PlatformActivity;
use Modules\Platform\Models\PlatformUser;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantBackup;
use Modules\Platform\Support\Provisioning\PostgresProvisioner;
use Modules\Platform\Support\Provisioning\TenantDatabaseNames;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Support\TestPlatform;

/*
| Spec §4.6: tenant:delete only after confirmation and a fresh backup; tenant:export produces
| a restorable dump; lifecycle Actions enforce platform roles themselves.
*/

beforeEach(function (): void {
    $this->slug = TestPlatform::temporarySlug();
    $this->tenant = app(CreateTenant::class)->handle(TestPlatform::tenantData($this->slug, 'fixture-x'))->tenant->refresh();
});

afterEach(function (): void {
    if (($tenant = Tenant::query()->where('slug', $this->slug)->first()) !== null) {
        TestPlatform::destroy($tenant);
    }
});

it('refuses to delete without typing the slug', function (): void {
    app(DeleteTenant::class)->handle($this->tenant, 'wrong');
})->throws(TenantLifecycleViolation::class, 'confirmation');

it('refuses to delete without a fresh successful backup', function (): void {
    app(DeleteTenant::class)->handle($this->tenant, $this->slug);
})->throws(TenantLifecycleViolation::class, 'backup');

it('deletes the database and roles once confirmed and backed up, keeping the landlord record', function (): void {
    TenantBackup::query()->create([
        'tenant_id' => $this->tenant->id,
        'kind' => 'logical',
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
        'status' => 'succeeded',
    ]);

    app(DeleteTenant::class)->handle($this->tenant, $this->slug);

    $names = TenantDatabaseNames::forSlug($this->slug);

    expect(app(PostgresProvisioner::class)->databaseExists($names->database))->toBeFalse()
        ->and(app(PostgresProvisioner::class)->roleExists($names->appRole))->toBeFalse()
        ->and($this->tenant->refresh()->status)->toBe(TenantStatus::Archived)
        ->and(PlatformActivity::query()->where('tenant_id', $this->tenant->id)->where('event', 'tenant.deleted')->exists())->toBeTrue();
});

it('exports the tenant database with pg_dump', function (): void {
    $binary = (new ExecutableFinder)->find('pg_dump');

    if ($binary === null) {
        $this->markTestSkipped('pg_dump is not on PATH.');
    }

    config(['platform.provisioning.pg_dump_binary' => $binary]);

    $backup = app(ExportTenant::class)->handle($this->tenant);

    expect($backup->status)->toBe('succeeded')
        ->and($backup->size_bytes)->toBeGreaterThan(0)
        ->and(is_file((string) $backup->location))->toBeTrue()
        ->and($this->tenant->refresh()->last_backup_at)->not->toBeNull();

    @unlink((string) $backup->location);
});

it('lets a support-role platform user suspend nothing', function (): void {
    $support = PlatformUser::query()->create([
        'name' => 'Support', 'email' => 'support-'.uniqid().'@platform.test', 'password' => 'irrelevant-password-1',
        'role' => PlatformRole::Support, 'status' => 'active',
    ]);

    app(Modules\Platform\Actions\ChangeTenantStatus::class)->handle($this->tenant, TenantStatus::Suspended, 'no', $support);
})->throws(Illuminate\Auth\Access\AuthorizationException::class);
