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
        // Page-builder pages: an ordered list of allow-listed sections, validated per type
        // on save (spec §5 "page builder"). The slug "home" is the home page.
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('slug', 120)->unique();
            $table->string('title');
            $table->string('status', 16)->default('draft');
            $table->jsonb('sections')->default('[]');
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_status_check CHECK (status IN ('draft', 'published'))");

        // Navigation menus by key ("main", "footer"), items as a two-level tree.
        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name');
            $table->jsonb('items')->default('[]');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
        Schema::dropIfExists('pages');
    }
};
