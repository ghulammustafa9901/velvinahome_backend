<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Str;
use Modules\Catalog\Contracts\SearchIndex;
use Modules\Identity\Models\StaffUser;
use Modules\Support\Actions\MigrateDatabase;
use Modules\Support\Actions\SeedDefaults;

/**
 * The test store: the `store_test` database brought to the current schema and defaults once per
 * run, exactly the way a deploy does (`app:migrate`), and its search collection rebuilt. Tests
 * write uniquely named data, so the database is reused across runs and no test depends on
 * another.
 */
final class TestStore
{
    private static bool $prepared = false;

    public static function prepare(): void
    {
        if (self::$prepared) {
            return;
        }

        app(MigrateDatabase::class)->handle();
        app(SeedDefaults::class)->handle();
        // Rebuild (not just ensure) so a schema change in code reaches the test collection.
        app(SearchIndex::class)->reindex();

        self::$prepared = true;
    }

    /**
     * A staff member with a known password.
     */
    public static function staff(string $password = 'correct-horse-9', ?string $role = 'Manager'): StaffUser
    {
        $staff = StaffUser::query()->create([
            'name' => 'Staff '.Str::random(5),
            'email' => 'staff-'.strtolower(Str::random(10)).'@example.test',
            'password' => $password,
            'status' => 'active',
        ]);

        if ($role !== null) {
            $staff->assignRole($role);
        }

        return $staff;
    }
}
