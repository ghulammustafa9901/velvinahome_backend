<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Intentionally empty. There is no single database to seed: the landlord is populated by
 * `platform:seed-demo`, and each tenant by the module seeders `tenant:create` runs.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void {}
}
