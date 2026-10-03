<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // The object-storage disks lost their "tenant_" names with the single-store conversion.
    public function up(): void
    {
        foreach (['disk', 'conversions_disk'] as $column) {
            DB::table('media')->where($column, 'tenant_media')->update([$column => 'media']);
            DB::table('media')->where($column, 'tenant_private')->update([$column => 'private']);
        }
    }

    public function down(): void
    {
        foreach (['disk', 'conversions_disk'] as $column) {
            DB::table('media')->where($column, 'media')->update([$column => 'tenant_media']);
            DB::table('media')->where($column, 'private')->update([$column => 'tenant_private']);
        }
    }
};
