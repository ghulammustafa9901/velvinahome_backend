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
        // Blog posts. Body is plain text with paragraphs (the storefront never renders stored
        // HTML); the cover is a site-relative or https URL, like page-builder images.
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('slug', 120)->unique();
            $table->string('title');
            $table->string('excerpt', 500)->nullable();
            $table->text('body');
            $table->string('cover_image_url', 2048)->nullable();
            $table->string('author_name', 120)->nullable();
            $table->jsonb('tags')->default('[]');
            $table->string('status', 16)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->timestampsTz();

            $table->index(['status', 'published_at']);
        });

        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_status_check CHECK (status IN ('draft', 'published'))");

        // Staff-defined structured content (FAQs, testimonials, store locations…): a type
        // declares its fields from an allow-list of kinds; entries hold the values.
        Schema::create('content_types', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 120);
            $table->jsonb('fields')->default('[]');
            $table->timestampsTz();
        });

        Schema::create('content_entries', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('content_type_id')->constrained('content_types')->cascadeOnDelete();
            $table->string('slug', 120);
            $table->string('title');
            $table->jsonb('values')->default('{}');
            $table->string('status', 16)->default('draft');
            $table->integer('position')->default(0);
            $table->timestampsTz();

            $table->unique(['content_type_id', 'slug']);
            $table->index(['content_type_id', 'status', 'position']);
        });

        DB::statement("ALTER TABLE content_entries ADD CONSTRAINT content_entries_status_check CHECK (status IN ('draft', 'published'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('content_entries');
        Schema::dropIfExists('content_types');
        Schema::dropIfExists('articles');
    }
};
