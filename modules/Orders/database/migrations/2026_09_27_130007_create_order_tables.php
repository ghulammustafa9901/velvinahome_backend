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
        // Human-facing order numbers, gap-tolerant, never reused.
        DB::statement('CREATE SEQUENCE order_number_seq START WITH 1001');

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('number', 32)->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('email');
            $table->string('phone', 32);
            $table->string('status', 32);
            // Mirror of the payment state machine for listing/filtering; Payments owns the truth.
            $table->string('payment_status', 24);
            $table->string('payment_method_code', 32);
            $table->string('shipping_method_code', 32)->nullable();
            $table->string('shipping_method_name')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('subtotal_amount');
            $table->bigInteger('discount_amount');
            $table->bigInteger('shipping_amount');
            $table->bigInteger('tax_amount');
            $table->bigInteger('total_amount');
            $table->boolean('tax_inclusive');
            $table->string('coupon_code', 50)->nullable();
            $table->text('customer_note')->nullable();
            // COD confirmation call outcome (spec §5: confirmed / unreachable / fake).
            $table->string('cod_confirmation', 16)->nullable();
            $table->unsignedSmallInteger('cod_attempts')->default(0);
            $table->text('cancel_reason')->nullable();
            // SHA-256 of the guest's order-access secret.
            $table->char('access_token_hash', 64);
            $table->string('idempotency_key', 100)->unique();
            $table->char('request_hash', 64);
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('placed_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('fulfilled_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index(['customer_id', 'placed_at']);
            $table->index(['status', 'placed_at']);
            $table->index('placed_at');
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending_payment', 'awaiting_confirmation', 'confirmed', 'processing', 'partially_fulfilled', 'fulfilled', 'delivered', 'completed', 'cancelled', 'returned'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('unpaid', 'awaiting_verification', 'paid', 'partially_refunded', 'refunded', 'voided'))");
        // The totals always reconcile, to the paisa (spec §6.4).
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_totals_check CHECK (
            subtotal_amount >= 0 AND discount_amount >= 0 AND shipping_amount >= 0 AND tax_amount >= 0
            AND total_amount = subtotal_amount - discount_amount + shipping_amount + CASE WHEN tax_inclusive THEN 0 ELSE tax_amount END
        )');

        // Frozen at placement: later catalog edits never change what was bought.
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->char('variant_ulid', 26);
            $table->char('product_ulid', 26);
            $table->string('sku', 100);
            $table->string('title');
            $table->string('variant_title')->nullable();
            $table->jsonb('options')->default('{}');
            $table->string('image_url', 2048)->nullable();
            $table->unsignedInteger('quantity');
            $table->char('currency', 3);
            $table->bigInteger('unit_price_amount');
            $table->bigInteger('subtotal_amount');
            $table->bigInteger('discount_amount');
            $table->bigInteger('tax_amount');
            $table->bigInteger('total_amount');
            $table->unsignedInteger('tax_rate_bps')->default(0);
            $table->char('reservation_ulid', 26)->nullable();
            $table->unsignedInteger('quantity_fulfilled')->default(0);
            $table->unsignedInteger('quantity_returned')->default(0);
            $table->timestampsTz();

            $table->index('variant_ulid');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantities_check CHECK (quantity > 0 AND quantity_fulfilled <= quantity AND quantity_returned <= quantity_fulfilled)');

        Schema::create('order_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('type', 16);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('phone', 32);
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city', 100);
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2);

            $table->unique(['order_id', 'type']);
        });

        // The order timeline. Append-only (UPDATE/DELETE revoked from the app role).
        Schema::create('order_state_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->string('actor_type', 16);
            $table->char('actor_ulid', 26)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['order_id', 'id']);
        });

        Schema::create('order_notes', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->char('author_ulid', 26)->nullable();
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('status', 16);
            $table->string('courier', 64)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->timestampTz('shipped_at');
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('returned_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_status_check CHECK (status IN ('in_transit', 'delivered', 'returned'))");

        Schema::create('shipment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');

            $table->unique(['shipment_id', 'order_item_id']);
        });

        Schema::create('order_returns', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->text('reason');
            $table->boolean('restocked');
            $table->char('actor_ulid', 26)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('order_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_return_id')->constrained('order_returns')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
        });
    }

    public function down(): void
    {
        foreach (['order_return_items', 'order_returns', 'shipment_items', 'shipments', 'order_notes', 'order_state_transitions', 'order_addresses', 'order_items', 'orders'] as $table) {
            Schema::dropIfExists($table);
        }

        DB::statement('DROP SEQUENCE IF EXISTS order_number_seq');
    }
};
