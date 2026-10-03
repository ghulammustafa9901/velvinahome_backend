<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Support\Actions\MigrateDatabase;
use Modules\Support\Actions\SeedDefaults;
use Spatie\Permission\Models\Role;

/*
| Spec §6.3: migrations are transactional. `app:migrate` runs every pending migration in one
| transaction as the migration role; a deliberately failing one leaves the database exactly as
| it was and fails the command, which blocks the release.
*/

const FAILING_MIGRATIONS = 'tests/Support/Migrations/Failing';

function probeTableExists(): bool
{
    return DB::table('pg_tables')->where('schemaname', 'public')->where('tablename', 'probe_first')->exists();
}

it('has nothing left to apply once the suite has migrated', function (): void {
    expect(app(MigrateDatabase::class)->handle())->toBe([]);
});

it('converges module defaults without undoing the store\'s own role changes', function (): void {
    Role::findByName('Manager', 'staff')->revokePermissionTo('customers.manage');

    try {
        app(SeedDefaults::class)->handle();

        expect(Role::findByName('Manager', 'staff')->hasPermissionTo('customers.manage'))->toBeFalse()
            ->and(Modules\Inventory\Models\Location::query()->count())->toBeGreaterThan(0);
    } finally {
        Role::findByName('Manager', 'staff')->givePermissionTo('customers.manage');
    }
});

it('rolls everything back when one of several pending migrations fails', function (): void {
    expect(fn () => app(MigrateDatabase::class)->handle([base_path(FAILING_MIGRATIONS)]))
        ->toThrow(RuntimeException::class, 'Deliberate failure');

    // The first probe migration succeeded inside the run and must have been undone with the
    // failing second one: the database is exactly as before.
    expect(probeTableExists())->toBeFalse()
        ->and(DB::table('migrations')->where('migration', '2099_01_01_000001_create_probe_first_table')->exists())->toBeFalse();
});

it('fails app:migrate with a non-zero exit when a migration fails', function (): void {
    app('migrator')->path(base_path(FAILING_MIGRATIONS));

    $this->artisan('app:migrate')
        ->expectsOutputToContain('rolled back')
        ->assertExitCode(1);

    expect(probeTableExists())->toBeFalse();
});
