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
        // Automatic promotions: no code to type. Money in minor units, rates in basis points.
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name', 120);
            $table->string('type', 24);
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->jsonb('conditions')->default('{}');
            $table->jsonb('config')->default('{}');
            $table->timestampsTz();

            $table->index(['is_active', 'priority']);
        });

        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_type_check CHECK (type IN ('percentage_sale', 'spend_tier', 'buy_x_get_y'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
