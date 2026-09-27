<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Platform\Actions\MigrateTenant;
use Modules\Platform\Actions\MigrateTenants;
use Modules\Platform\Models\TenantMigrationRun;
use Modules\Platform\Tenancy\TenantDatabaseConfig;
use Modules\Support\Modules\ModuleRegistry;
use Tests\Support\TestPlatform;

/*
| Spec §4.7, §6.3, §15: tenant migrations are transactional; a deliberately failing migration
| leaves that tenant's database unchanged, is recorded, and fails the command.
*/

function registerProbeMigrations(string $directory): void
{
    app(ModuleRegistry::class)->register('Probe', $directory, null, [], []);
}

function tenantTableExists(string $slug, string $table): bool
{
    /** @var TenantDatabaseConfig $database */
    $database = TestPlatform::brand($slug)->database();

    config(['database.connections.probe' => $database->migratorConnection()]);
    DB::purge('probe');

    return DB::connection('probe')->table('pg_tables')->where('schemaname', 'public')->where('tablename', $table)->exists();
}

function migrationRecorded(string $slug, string $migration): bool
{
    tenantTableExists($slug, 'migrations');

    return DB::connection('probe')->table('migrations')->where('migration', $migration)->exists();
}

it('applies pending migrations to every tenant and records each run', function (): void {
    $runs = app(MigrateTenants::class)->handle([TestPlatform::BRAND_A, TestPlatform::BRAND_B, TestPlatform::BRAND_C]);

    expect($runs)->toHaveCount(3)
        ->and(collect($runs)->pluck('status')->unique()->all())->toBe(['succeeded']);
});

it('converges module defaults after migrating without undoing a brand\'s own role changes', function (): void {
    $brand = TestPlatform::brand(TestPlatform::BRAND_C);

    $brand->run(fn () => Spatie\Permission\Models\Role::findByName('Manager', 'staff')->revokePermissionTo('customers.manage'));

    try {
        app(MigrateTenants::class)->handle([TestPlatform::BRAND_C]);

        expect($brand->run(fn (): bool => Spatie\Permission\Models\Role::findByName('Manager', 'staff')->hasPermissionTo('customers.manage')))->toBeFalse()
            ->and($brand->run(fn (): int => Modules\Inventory\Models\Location::query()->count()))->toBeGreaterThan(0);
    } finally {
        $brand->run(fn () => Spatie\Permission\Models\Role::findByName('Manager', 'staff')->givePermissionTo('customers.manage'));
    }
});

it('rolls a tenant back completely when one of several pending migrations fails', function (): void {
    registerProbeMigrations(base_path('tests/Support/Migrations/Failing'));

    $run = app(MigrateTenant::class)->handle(TestPlatform::brand(TestPlatform::BRAND_A));

    expect($run->status)->toBe('failed')
        ->and($run->error)->toContain('Deliberate failure')
        // The first probe migration succeeded inside the run, and must have been undone
        // with the failing second one: the database is exactly as before.
        ->and(tenantTableExists(TestPlatform::BRAND_A, 'probe_first'))->toBeFalse()
        ->and(migrationRecorded(TestPlatform::BRAND_A, '2099_01_01_000001_create_probe_first_table'))->toBeFalse()
        ->and(TenantMigrationRun::query()->whereKey($run->id)->value('status'))->toBe('failed');
});

it('fails tenants:migrate with a non-zero exit when any tenant fails', function (): void {
    registerProbeMigrations(base_path('tests/Support/Migrations/Failing'));

    $this->artisan('tenants:migrate', ['--tenant' => [TestPlatform::BRAND_B]])
        ->expectsOutputToContain('FAILED')
        ->assertExitCode(1);

    expect(tenantTableExists(TestPlatform::BRAND_B, 'probe_first'))->toBeFalse();
});
