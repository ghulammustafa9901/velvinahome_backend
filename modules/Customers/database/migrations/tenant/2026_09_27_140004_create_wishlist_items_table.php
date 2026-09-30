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
        Schema::create('wishlist_items', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->char('product_ulid', 26);
            $table->char('variant_ulid', 26)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        // A product (or a specific variant of it) is on a wishlist once.
        DB::statement('CREATE UNIQUE INDEX wishlist_items_unique ON wishlist_items (customer_id, product_ulid, variant_ulid) NULLS NOT DISTINCT');
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
    }
};
