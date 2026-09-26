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
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->jsonb('default_features')->default('{}');
            $table->timestampsTz();
        });

        // The contracting and billing party. Groups brands; holds no brand data (spec §4.1a).
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('legal_name');
            $table->string('display_name');
            $table->string('slug')->unique();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->char('country_code', 2)->default('PK');
            $table->string('contract_status')->default('active');
            $table->text('billing_notes')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_contract_status_check CHECK (contract_status IN ('prospect', 'active', 'suspended', 'ended'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
        Schema::dropIfExists('plans');
    }
};
