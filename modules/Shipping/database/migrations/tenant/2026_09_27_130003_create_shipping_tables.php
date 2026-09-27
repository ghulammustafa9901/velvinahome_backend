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
        // A zone matches an address when every non-empty list contains it; the most specific
        // matching zone (cities > provinces > countries) supplies the methods.
        Schema::create('shipping_zones', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name');
            $table->jsonb('countries')->default('[]');
            $table->jsonb('provinces')->default('[]');
            $table->jsonb('cities')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('shipping_zone_id')->constrained('shipping_zones')->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_cod_allowed')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('min_days')->nullable();
            $table->unsignedSmallInteger('max_days')->nullable();
            $table->integer('position')->default(0);
            $table->timestampsTz();

            $table->unique(['shipping_zone_id', 'code']);
        });

        Schema::create('shipping_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipping_method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $table->string('strategy', 16)->default('flat');
            // Weight in grams or order value in minor units, per strategy; inclusive bounds.
            $table->bigInteger('min_value')->nullable();
            $table->bigInteger('max_value')->nullable();
            $table->bigInteger('amount_amount');
            $table->char('amount_currency', 3);
            $table->bigInteger('free_over_amount')->nullable();
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE shipping_rates ADD CONSTRAINT shipping_rates_strategy_check CHECK (strategy IN ('flat', 'weight', 'order_value'))");
        DB::statement('ALTER TABLE shipping_rates ADD CONSTRAINT shipping_rates_amount_check CHECK (amount_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
        Schema::dropIfExists('shipping_methods');
        Schema::dropIfExists('shipping_zones');
    }
};
