<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Support\Actions\SeedDefaults;

/**
 * Every module's defaults (roles and permissions, settings, a stock location, shipping, payment
 * methods, email templates, pages). Idempotent; `app:migrate` runs the same seeders.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(SeedDefaults $defaults): void
    {
        $defaults->handle();
    }
}
