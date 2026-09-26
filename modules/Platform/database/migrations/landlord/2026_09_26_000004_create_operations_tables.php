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
        // Non-sensitive aggregates only (spec §4.9): counts and totals, never a customer or
        // an order row.
        Schema::create('tenant_metrics_daily', function (Blueprint $table): void {
            $table->id();
            $table->char('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('orders_count')->default(0);
            $table->bigInteger('gross_sales_amount')->default(0);
            $table->char('gross_sales_currency', 3);
            $table->unsignedInteger('sessions_count')->nullable();
            $table->timestampTz('computed_at');

            $table->unique(['tenant_id', 'date']);
        });

        Schema::create('tenant_backups', function (Blueprint $table): void {
            $table->id();
            $table->char('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->string('kind');
            $table->text('location')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('status');
            $table->text('error')->nullable();

            $table->index(['tenant_id', 'finished_at']);
        });

        DB::statement("ALTER TABLE tenant_backups ADD CONSTRAINT tenant_backups_kind_check CHECK (kind IN ('logical', 'pitr', 'export'))");
        DB::statement("ALTER TABLE tenant_backups ADD CONSTRAINT tenant_backups_status_check CHECK (status IN ('running', 'succeeded', 'failed'))");

        // What makes "a failed tenant migration blocks the release" reportable per tenant.
        Schema::create('tenant_migration_runs', function (Blueprint $table): void {
            $table->id();
            $table->char('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('release_version');
            $table->unsignedInteger('migrations_applied')->default(0);
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();

            $table->index(['tenant_id', 'started_at']);
        });

        DB::statement("ALTER TABLE tenant_migration_runs ADD CONSTRAINT tenant_migration_runs_status_check CHECK (status IN ('running', 'succeeded', 'failed'))");

        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('version');
            $table->string('git_sha', 40)->nullable();
            $table->timestampTz('deployed_at');
            $table->string('deployed_by')->nullable();
            $table->text('notes')->nullable();
        });

        // Queue bookkeeping lives centrally: workers are shared across tenants (spec §4.4).
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestampTz('failed_at')->useCurrent();
        });

        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('tenant_migration_runs');
        Schema::dropIfExists('tenant_backups');
        Schema::dropIfExists('tenant_metrics_daily');
    }
};
