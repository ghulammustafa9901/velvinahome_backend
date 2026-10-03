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
        // Staff: the people who operate the store admin. Separate table, separate guard from
        // customers (spec §6.2).
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->string('status')->default('active');
            $table->rememberToken();
            $table->timestampTz('last_login_at')->nullable();
            // Historical (multi-brand owners); dropped by 2026_10_04_100001.
            $table->char('landlord_owner_identity_ulid', 26)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('CREATE UNIQUE INDEX users_email_live_unique ON users (lower(email)) WHERE deleted_at IS NULL');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'disabled'))");

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            // Null for guest checkout until the account is claimed.
            $table->string('password')->nullable();
            $table->boolean('accepts_marketing')->default(false);
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('CREATE UNIQUE INDEX customers_email_live_unique ON customers (lower(email)) WHERE deleted_at IS NULL');

        // Append-only: every sign-in attempt, successful or not (spec §6.2, §9).
        Schema::create('login_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 16);
            $table->string('email_attempted');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('successful');
            $table->string('failure_reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['email_attempted', 'created_at']);
        });

        DB::statement("ALTER TABLE login_audits ADD CONSTRAINT login_audits_subject_type_check CHECK (subject_type IN ('staff', 'customer'))");

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('login_audits');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('users');
    }
};
