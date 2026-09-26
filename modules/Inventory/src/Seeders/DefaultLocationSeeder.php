<?php

declare(strict_types=1);

namespace Modules\Inventory\Seeders;

use Illuminate\Database\Seeder;
use Modules\Inventory\Models\Location;

/**
 * Every tenant has at least one location, even if it only ever uses one (spec §6.5).
 */
final class DefaultLocationSeeder extends Seeder
{
    public function run(): void
    {
        if (Location::query()->exists()) {
            return;
        }

        Location::query()->create([
            'name' => 'Main warehouse',
            'code' => 'MAIN',
            'type' => 'warehouse',
            'is_default' => true,
            'is_active' => true,
            'priority' => 100,
        ]);
    }
}
