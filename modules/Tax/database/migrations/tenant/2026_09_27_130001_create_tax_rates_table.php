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
        // Rates in basis points (1800 = 18%), never floats (spec §6.3). The most specific
        // active rate wins: country + province, then country, then a global rate.
        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name');
            $table->unsignedInteger('rate_bps');
            $table->char('country_code', 2)->nullable();
            $table->string('province')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_rate_check CHECK (rate_bps <= 10000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
