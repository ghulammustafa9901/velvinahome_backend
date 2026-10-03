<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Search synonyms (spec §6.10), managed in the admin — where Roman Urdu and local
 * spellings live ("takia" = cushion, "lown" → lawn). PostgreSQL holds them; Typesense gets a
 * full copy on every change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_synonyms', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            // Set for a one-way synonym (searching any term finds the root); null for a
            // multi-way group where every term finds every other.
            $table->string('root', 100)->nullable();
            $table->jsonb('terms');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_synonyms');
    }
};
