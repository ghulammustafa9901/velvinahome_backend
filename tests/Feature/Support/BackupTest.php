<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Support\Actions\BackupDatabase;
use Modules\Support\Actions\RestoreDrill;

/*
| Backups and the restore drill (spec §9): a dump with a checksum manifest, restored into the
| scratch database and compared table by table; a tampered dump is refused; a row written after
| the dump shows up as a difference.
*/

beforeEach(function (): void {
    // Never the real backup directory: the retention test prunes.
    $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup-test-'.uniqid();
    config(['store.backup.path' => $this->directory]);
});

afterEach(function (): void {
    foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->directory);
});

it('writes a dump with a checksum manifest', function (): void {
    $dump = app(BackupDatabase::class)->handle();
    $manifest = json_decode((string) file_get_contents(BackupDatabase::manifestPath($dump)), true);

    expect($dump)->toBeFile()
        ->and($manifest['sha256'])->toBe(hash_file('sha256', $dump))
        ->and($manifest['database'])->toBe(config('database.connections.pgsql.database'))
        ->and($manifest)->toHaveKey('files');
});

it('restores a dump into the scratch database and finds it identical', function (): void {
    $dump = app(BackupDatabase::class)->handle();

    expect(app(RestoreDrill::class)->handle($dump))->toBe([]);
});

it('reports rows written after the backup as differences', function (): void {
    $dump = app(BackupDatabase::class)->handle();
    DB::table('settings')->insert(['group' => 'test', 'key' => 'after_backup_'.uniqid(), 'value' => json_encode(1), 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);

    try {
        expect(implode("\n", app(RestoreDrill::class)->handle($dump)))->toContain('settings:');
    } finally {
        DB::table('settings')->where('group', 'test')->where('key', 'like', 'after_backup_%')->delete();
    }
});

it('refuses a dump that no longer matches its checksum', function (): void {
    $dump = app(BackupDatabase::class)->handle();
    file_put_contents($dump, 'tampered', FILE_APPEND);

    app(RestoreDrill::class)->handle($dump);
})->throws(RuntimeException::class, 'checksum');

it('runs the drill from the command and keeps only the newest dumps', function (): void {
    config(['store.backup.retention' => 2]);

    foreach (range(1, 3) as $i) {
        $this->artisan('db:backup', $i === 3 ? ['--drill' => true] : [])->assertSuccessful();
    }

    expect(glob($this->directory.DIRECTORY_SEPARATOR.'*.dump') ?: [])->toHaveCount(2)
        ->and(glob($this->directory.DIRECTORY_SEPARATOR.'*.json') ?: [])->toHaveCount(2);
});
