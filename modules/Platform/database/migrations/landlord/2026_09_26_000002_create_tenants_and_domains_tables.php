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
        // One row per brand: one website, one database (spec §4.1).
        Schema::create('tenants', function (Blueprint $table): void {
            // The tenant id is a ULID. It appears in cache tags, storage prefixes and search
            // collection names, so it is never reused and never derived from the slug.
            $table->char('id', 26)->primary();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('name');
            $table->string('slug', 40)->unique();
            $table->string('status')->default('provisioning');
            $table->foreignId('plan_id')->nullable()->constrained('plans')->restrictOnDelete();
            $table->string('topology')->default('shared');
            $table->string('schema_version')->nullable();
            $table->char('default_currency', 3)->default('PKR');
            $table->string('default_timezone')->default('Asia/Karachi');
            $table->string('default_locale', 10)->default('en');
            $table->string('theme_id')->default('default');
            $table->jsonb('provisioning_state')->default('{}');
            $table->string('health_status')->nullable();
            $table->timestampTz('health_checked_at')->nullable();
            $table->timestampTz('last_backup_at')->nullable();
            // stancl/tenancy virtual-column overflow. Nothing sensitive is ever stored here.
            $table->jsonb('data')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('status');
        });

        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('provisioning', 'active', 'suspended', 'archived'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_topology_check CHECK (topology IN ('shared', 'dedicated'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_slug_format_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");

        Schema::create('domains', function (Blueprint $table): void {
            $table->id();
            $table->char('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Unique across the whole table: that is what makes hostname resolution
            // unambiguous. Stored lower-case.
            $table->string('hostname')->unique();
            $table->string('kind');
            $table->boolean('is_primary')->default(true);
            $table->timestampsTz();

            $table->index('tenant_id');
        });

        DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_kind_check CHECK (kind IN ('storefront', 'admin', 'api'))");
        DB::statement('ALTER TABLE domains ADD CONSTRAINT domains_hostname_lowercase_check CHECK (hostname = lower(hostname))');
        DB::statement('CREATE UNIQUE INDEX domains_one_primary_per_kind ON domains (tenant_id, kind) WHERE is_primary');

        // Connection details per tenant. Passwords use Laravel encrypted casts, so the landlord
        // database alone is not enough to reach a tenant database (database-roles.md §4).
        Schema::create('tenant_databases', function (Blueprint $table): void {
            $table->id();
            $table->char('tenant_id', 26)->unique();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->string('host');
            $table->unsignedInteger('port');
            $table->string('database')->unique();
            $table->string('app_username')->unique();
            $table->text('app_password');
            $table->string('migration_username')->unique();
            $table->text('migration_password');
            $table->timestampsTz();
        });

        Schema::create('tenant_feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->char('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('key');
            $table->boolean('enabled');
            $table->jsonb('value')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_feature_flags');
        Schema::dropIfExists('tenant_databases');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('tenants');
    }
};
