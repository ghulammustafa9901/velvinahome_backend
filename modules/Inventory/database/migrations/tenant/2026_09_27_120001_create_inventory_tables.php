<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory is a ledger (spec §6.5): `stock_levels` holds the running balance, and
 * `inventory_movements` is the append-only source of truth it must always equal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name');
            $table->string('code', 32)->unique();
            $table->string('type')->default('warehouse');
            $table->jsonb('address')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE locations ADD CONSTRAINT locations_type_check CHECK (type IN ('warehouse', 'shop', '3pl'))");
        DB::statement('CREATE UNIQUE INDEX locations_one_default ON locations (is_default) WHERE is_default');

        Schema::create('stock_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('incoming')->default(0);
            $table->integer('low_stock_threshold')->nullable();
            $table->integer('reorder_point')->nullable();
            $table->integer('reorder_qty')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->unique(['variant_id', 'location_id']);
            $table->index('location_id');
        });
        // `on_hand >= 0` unless the variant allows backorders is enforced in the Actions (the
        // flag lives on the variant); `reserved` can never be negative anywhere.
        DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_reserved_check CHECK (reserved >= 0)');
        DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_incoming_check CHECK (incoming >= 0)');

        // Append-only: UPDATE, DELETE and TRUNCATE are revoked from the application role.
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('type', 32);
            $table->integer('quantity');
            $table->integer('balance_after');
            $table->bigInteger('unit_cost_amount')->nullable();
            $table->char('unit_cost_currency', 3)->nullable();
            $table->string('reference_type', 32)->nullable();
            $table->char('reference_ulid', 26)->nullable();
            $table->text('reason')->nullable();
            $table->char('actor_ulid', 26)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['variant_id', 'location_id', 'created_at']);
            $table->index(['reference_type', 'reference_ulid']);
        });
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_type_check CHECK (type IN ('purchase_receipt', 'sale', 'return', 'adjustment', 'transfer_out', 'transfer_in', 'stock_count', 'damage'))");
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_nonzero_check CHECK (quantity <> 0)');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_reason_check CHECK (type NOT IN ('adjustment', 'damage') OR length(trim(coalesce(reason, ''))) > 0)");

        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->integer('quantity');
            $table->string('reference_type', 32);
            $table->char('reference_ulid', 26);
            $table->string('status', 16)->default('active');
            $table->timestampTz('expires_at');
            $table->timestampTz('converted_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'expires_at']);
            $table->index(['reference_type', 'reference_ulid']);
        });
        DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_quantity_check CHECK (quantity > 0)');
        DB::statement("ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_status_check CHECK (status IN ('active', 'converted', 'released', 'expired'))");

        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->jsonb('address')->nullable();
            $table->timestampsTz();
        });

        // Documents: each groups the movements it caused (movements reference them by ULID).
        foreach (['goods_receipts', 'stock_transfers', 'stock_counts'] as $document) {
            Schema::create($document, function (Blueprint $table) use ($document): void {
                $table->id();
                $table->char('ulid', 26)->unique();
                if ($document === 'goods_receipts') {
                    $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
                    $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
                    $table->string('supplier_reference')->nullable();
                }
                if ($document === 'stock_transfers') {
                    $table->foreignId('from_location_id')->constrained('locations')->restrictOnDelete();
                    $table->foreignId('to_location_id')->constrained('locations')->restrictOnDelete();
                }
                if ($document === 'stock_counts') {
                    $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
                }
                $table->text('notes')->nullable();
                $table->char('actor_ulid', 26)->nullable();
                $table->timestampsTz();
            });
        }

        Schema::create('document_lines', function (Blueprint $table): void {
            $table->id();
            $table->string('document_type', 32);
            $table->char('document_ulid', 26);
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            // Receipt: quantity received; transfer: quantity moved; count: quantity counted.
            $table->integer('quantity');
            $table->integer('expected_quantity')->nullable();
            $table->bigInteger('unit_cost_amount')->nullable();
            $table->char('unit_cost_currency', 3)->nullable();

            $table->index(['document_type', 'document_ulid']);
        });

        // Append-only history for stock and valuation reports (spec §6.5.6).
        Schema::create('stock_snapshots_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->integer('on_hand');
            $table->bigInteger('unit_cost_amount')->nullable();
            $table->bigInteger('valuation_amount')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['date', 'variant_id', 'location_id']);
        });

        Schema::create('inventory_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->timestampTz('ran_at');
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->integer('on_hand_recorded');
            $table->integer('movements_sum');
            $table->integer('difference');
            $table->timestampTz('resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['inventory_reconciliations', 'stock_snapshots_daily', 'document_lines', 'stock_counts', 'stock_transfers',
            'goods_receipts', 'suppliers', 'stock_reservations', 'inventory_movements', 'stock_levels', 'locations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
