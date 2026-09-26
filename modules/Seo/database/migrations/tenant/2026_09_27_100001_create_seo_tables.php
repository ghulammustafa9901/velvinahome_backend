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
        // Written automatically on every slug change and by importers (spec §6.13) — the thing
        // that keeps a WooCommerce migration's search rankings.
        Schema::create('redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('from_path', 2048)->unique();
            $table->string('to_path', 2048);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestampTz('last_hit_at')->nullable();
            $table->string('source')->default('manual');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE redirects ADD CONSTRAINT redirects_status_check CHECK (status_code IN (301, 302, 307, 308, 410))');
        DB::statement("ALTER TABLE redirects ADD CONSTRAINT redirects_source_check CHECK (source IN ('slug_change', 'import', 'manual'))");
        DB::statement('ALTER TABLE redirects ADD CONSTRAINT redirects_no_self_loop CHECK (from_path <> to_path)');

        // Keyed by the subject's public id so no module needs a foreign key into another.
        Schema::create('seo_meta', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 40);
            $table->char('subject_ulid', 26);
            $table->string('title')->nullable();
            $table->string('description', 500)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->string('robots', 64)->nullable();
            $table->string('og_image_url', 2048)->nullable();
            $table->timestampsTz();

            $table->unique(['subject_type', 'subject_ulid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_meta');
        Schema::dropIfExists('redirects');
    }
};
