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
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // SHA-256 of the guest cart's secret; the plain token exists only on the client.
            $table->char('token_hash', 64)->nullable();
            $table->char('currency', 3);
            $table->string('coupon_code', 50)->nullable();
            $table->string('status', 16)->default('active');
            $table->char('order_ulid', 26)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->index(['customer_id', 'status']);
        });

        DB::statement("ALTER TABLE carts ADD CONSTRAINT carts_status_check CHECK (status IN ('active', 'converted', 'merged'))");

        // Lines hold only what the customer chose. Prices are never stored here: every read
        // prices the cart afresh (spec §6.4).
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->char('variant_ulid', 26);
            $table->unsignedInteger('quantity');
            $table->timestampsTz();

            $table->unique(['cart_id', 'variant_ulid']);
        });

        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_check CHECK (quantity BETWEEN 1 AND 999)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
