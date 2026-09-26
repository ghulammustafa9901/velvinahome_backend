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
        // The Platform Owner's team. A separate population from tenant staff and customers,
        // with its own guard and its own table (spec §6.2).
        Schema::create('platform_users', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->string('role')->default('support');
            $table->string('status')->default('active');
            $table->rememberToken();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE platform_users ADD CONSTRAINT platform_users_role_check CHECK (role IN ('owner', 'engineer', 'support'))");
        DB::statement("ALTER TABLE platform_users ADD CONSTRAINT platform_users_status_check CHECK (status IN ('active', 'disabled'))");

        // Append-only (UPDATE/DELETE revoked from landlord_app after migrating).
        Schema::create('platform_activity_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_user_id')->nullable()->constrained('platform_users')->restrictOnDelete();
            $table->string('event');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->char('tenant_id', 26)->nullable();
            $table->jsonb('properties')->default('{}');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['platform_user_id', 'created_at']);
        });

        // Append-only: success and failure, with IP and user agent (spec §6.2).
        Schema::create('platform_login_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('email_attempted');
            $table->foreignId('platform_user_id')->nullable()->constrained('platform_users')->restrictOnDelete();
            $table->boolean('successful');
            $table->string('failure_reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['email_attempted', 'created_at']);
        });

        // Support access into a tenant admin: who, when, why — always (spec §4.9). Rows are
        // only ever inserted and then closed (ended_at), so UPDATE stays allowed on this one
        // table but DELETE does not.
        Schema::create('impersonation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('platform_user_id')->constrained('platform_users')->restrictOnDelete();
            $table->char('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->char('target_staff_user_ulid', 26);
            $table->text('reason');
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('started_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
        });

        DB::statement("ALTER TABLE impersonation_sessions ADD CONSTRAINT impersonation_sessions_reason_check CHECK (length(trim(reason)) >= 10)");
        DB::statement('ALTER TABLE impersonation_sessions ADD CONSTRAINT impersonation_sessions_expiry_check CHECK (expires_at > started_at)');

        // Multi-brand owner access (spec §4.1a): one landlord identity, linked to a staff row
        // inside each brand's own database. Only a ULID pointer crosses the boundary.
        Schema::create('company_owner_identities', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('status')->default('active');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('company_owner_brand_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_owner_identity_id')->constrained('company_owner_identities')->restrictOnDelete();
            $table->char('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->char('tenant_staff_user_ulid', 26);
            $table->foreignId('granted_by_platform_user_id')->nullable()->constrained('platform_users')->restrictOnDelete();
            $table->timestampTz('granted_at');
            $table->timestampTz('revoked_at')->nullable();

            $table->unique(['company_owner_identity_id', 'tenant_id']);
        });

        // Single-use, short-lived hand-off tokens for the brand switcher. Only the SHA-256 of
        // the token is stored.
        Schema::create('brand_switch_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('company_owner_identity_id')->constrained('company_owner_identities')->restrictOnDelete();
            $table->char('from_tenant_id', 26);
            $table->char('to_tenant_id', 26);
            $table->foreign('to_tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_switch_tokens');
        Schema::dropIfExists('company_owner_brand_links');
        Schema::dropIfExists('company_owner_identities');
        Schema::dropIfExists('impersonation_sessions');
        Schema::dropIfExists('platform_login_audits');
        Schema::dropIfExists('platform_activity_log');
        Schema::dropIfExists('platform_users');
    }
};
