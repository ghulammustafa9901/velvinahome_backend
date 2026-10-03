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
        // One run of an importer: what was asked, how far it got, and what it found.
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('source', 32);
            $table->string('status', 16);
            $table->boolean('dry_run');
            $table->jsonb('options')->default('{}');
            // Source credentials (e.g. WooCommerce API keys), encrypted; cleared when the run ends.
            $table->text('credentials')->nullable();
            $table->string('file_path')->nullable();
            $table->string('step', 32)->nullable();
            $table->jsonb('counts')->default('{}');
            $table->jsonb('issues')->default('[]');
            $table->unsignedInteger('issue_count')->default(0);
            $table->text('error')->nullable();
            $table->char('actor_ulid', 26)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE import_runs ADD CONSTRAINT import_runs_status_check CHECK (status IN ('queued', 'running', 'completed', 'failed'))");

        // Which record here came from which record there, so a re-run updates instead of
        // duplicating. `source` names the exact origin (e.g. "woocommerce:shop.example.com").
        Schema::create('import_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 150);
            $table->string('entity', 32);
            $table->string('external_id', 100);
            $table->string('local_ref', 64);
            $table->timestampsTz();

            $table->unique(['source', 'entity', 'external_id']);
        });

        // Orders from the previous store: history for staff and customers, read-only. They are
        // not live orders — no stock, payments or reports are derived from them.
        Schema::create('imported_orders', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('source', 150);
            $table->string('external_id', 100);
            $table->string('number', 64);
            $table->string('status', 32);
            $table->char('currency', 3);
            $table->bigInteger('subtotal_amount');
            $table->bigInteger('discount_amount');
            $table->bigInteger('shipping_amount');
            $table->bigInteger('tax_amount');
            $table->bigInteger('total_amount');
            $table->string('email')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('customer_name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('payment_method')->nullable();
            $table->jsonb('shipping_address')->nullable();
            $table->jsonb('lines')->default('[]');
            $table->timestampTz('placed_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['source', 'external_id']);
            $table->index('placed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_orders');
        Schema::dropIfExists('import_mappings');
        Schema::dropIfExists('import_runs');
    }
};
