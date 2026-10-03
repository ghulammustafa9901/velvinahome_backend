<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The store's couriers. "manual" couriers only build tracking links; an API adapter
        // (when a courier account exists) books shipments and reports their status.
        Schema::create('couriers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 64);
            $table->string('adapter', 32)->default('manual');
            $table->string('tracking_url_template', 2048)->nullable();
            $table->text('credentials')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });

        // Pakistani couriers a store most likely uses; tracking links are left for staff to
        // fill in from their courier account, never guessed.
        $now = now();
        foreach (['Leopards', 'TCS', 'PostEx', 'Trax', 'M&P', 'Call Courier', 'BlueEx'] as $position => $name) {
            DB::table('couriers')->insertOrIgnore([
                'code' => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'),
                'name' => $name,
                'adapter' => 'manual',
                'is_enabled' => true,
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('couriers');
    }
};
