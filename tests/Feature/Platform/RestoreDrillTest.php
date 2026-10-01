<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Actions\ChangeTenantStatus;
use Modules\Platform\Actions\CreateTenant;
use Modules\Platform\Actions\ExportTenant;
use Modules\Platform\Actions\RestoreTenant;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\PlatformActivity;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantBackup;
use Modules\Platform\Support\Provisioning\PostgresProvisioner;
use Modules\Platform\Support\Provisioning\TenantDatabaseNames;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Support\TestPlatform;

/*
| Spec §9: backups are restorable, and that is proven by a drill (runbooks/restore-tenant.md).
*/

function pgBinary(string $key, string $name): ?string
{
    $configured = (string) config('platform.provisioning.'.$key);

    return is_file($configured) ? $configured : (new ExecutableFinder)->find($name);
}

beforeEach(function (): void {
    $dump = pgBinary('pg_dump_binary', 'pg_dump');
    $restore = pgBinary('pg_restore_binary', 'pg_restore');

    if ($dump === null || $restore === null) {
        $this->markTestSkipped('pg_dump/pg_restore are neither configured (PG_DUMP_BINARY, PG_RESTORE_BINARY) nor on PATH.');
    }

    config(['platform.provisioning.pg_dump_binary' => $dump, 'platform.provisioning.pg_restore_binary' => $restore]);

    $this->slug = TestPlatform::temporarySlug();
    $this->tenant = app(CreateTenant::class)->handle(TestPlatform::tenantData($this->slug, 'fixture-x'))->tenant->refresh();
    $this->tenant->run(fn () => DB::table('brands')->insert([
        'ulid' => (string) Str::ulid(), 'name' => 'Before backup', 'slug' => 'before-backup', 'created_at' => now(), 'updated_at' => now(),
    ]));
    $this->backup = app(ExportTenant::class)->handle($this->tenant);
});

afterEach(function (): void {
    if (isset($this->backup)) {
        @unlink((string) $this->backup->location);
        @unlink(str_replace('.dump', '.media.json', (string) $this->backup->location));
    }

    if (isset($this->slug) && ($tenant = Tenant::query()->where('slug', $this->slug)->first()) !== null) {
        TestPlatform::destroy($tenant);
    }
});

function scratchDatabases(string $slug): int
{
    return app(PostgresProvisioner::class)->databaseExists(TenantDatabaseNames::forSlug($slug)->database)
        ? DB::connection('provisioner')->table('pg_database')->where('datname', 'like', TenantDatabaseNames::forSlug($slug)->database.'%_drill_%')->count()
        : -1;
}

it('restores an export into a scratch database, finds it identical, and drops the copy', function (): void {
    $run = app(RestoreTenant::class)->handle($this->tenant, $this->backup);

    expect($run->status)->toBe('succeeded', (string) $run->error)
        ->and($run->differences)->toBe([])
        ->and($run->tables_checked)->toBeGreaterThan(20)
        ->and($run->rows_checked)->toBeGreaterThan(0)
        ->and(scratchDatabases($this->slug))->toBe(0)
        ->and(PlatformActivity::query()->where('tenant_id', $this->tenant->id)->where('event', 'tenant.restore_drill')->exists())->toBeTrue();
});

it('reports rows written after the backup as differences', function (): void {
    $this->tenant->run(fn () => DB::table('brands')->insert([
        'ulid' => (string) Str::ulid(), 'name' => 'After backup', 'slug' => 'after-backup', 'created_at' => now(), 'updated_at' => now(),
    ]));

    $run = app(RestoreTenant::class)->handle($this->tenant, $this->backup);

    expect($run->status)->toBe('failed')
        ->and($run->differences)->toContain('brands: 1 rows restored, 2 live.');
});

it('refuses a backup whose file no longer matches its checksum', function (): void {
    TenantBackup::query()->whereKey($this->backup->id)->update(['checksum' => str_repeat('0', 64)]);

    $run = app(RestoreTenant::class)->handle($this->tenant, $this->backup->refresh());

    expect($run->status)->toBe('failed')->and($run->error)->toContain('checksum');
});

it('refuses an in-place restore while the brand is active', function (): void {
    $run = app(RestoreTenant::class)->handle($this->tenant, $this->backup, 'in_place');

    expect($run->status)->toBe('failed')->and($run->error)->toContain('Suspend');
});

it('restores a suspended brand in place to the backed-up state', function (): void {
    $this->tenant->run(fn () => DB::table('brands')->insert([
        'ulid' => (string) Str::ulid(), 'name' => 'After backup', 'slug' => 'after-backup', 'created_at' => now(), 'updated_at' => now(),
    ]));
    app(ChangeTenantStatus::class)->handle($this->tenant, TenantStatus::Suspended, 'restore test');

    $run = app(RestoreTenant::class)->handle($this->tenant->refresh(), $this->backup, 'in_place');

    expect($run->error)->toBeNull()->and($run->status)->toBe('succeeded')
        ->and($this->tenant->run(fn () => DB::table('brands')->pluck('slug')->all()))->toBe(['before-backup'])
        // The application role can still write after the restore (privileges re-applied).
        ->and($this->tenant->run(fn () => DB::table('brands')->insert([
            'ulid' => (string) Str::ulid(), 'name' => 'Later', 'slug' => 'later', 'created_at' => now(), 'updated_at' => now(),
        ])))->toBeTrue()
        // A safety export was taken before replacing the database.
        ->and(TenantBackup::query()->where('tenant_id', $this->tenant->id)->where('kind', 'export')->count())->toBe(2);

    TenantBackup::query()->where('tenant_id', $this->tenant->id)->whereKeyNot($this->backup->id)->get()
        ->each(fn (TenantBackup $b) => @unlink((string) $b->location));
});
