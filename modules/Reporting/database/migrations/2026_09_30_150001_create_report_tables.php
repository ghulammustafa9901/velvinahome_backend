<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per brand-local calendar day and currency. Rebuilt whole from the order and
        // payment records, never incremented, so a rebuild always converges on the truth.
        Schema::create('report_sales_days', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->char('currency', 3);
            $table->unsignedInteger('orders_placed');
            $table->unsignedInteger('orders_cancelled');
            $table->unsignedInteger('orders_returned');
            $table->unsignedInteger('units');
            $table->bigInteger('subtotal_amount');
            $table->bigInteger('discount_amount');
            $table->bigInteger('shipping_amount');
            $table->bigInteger('tax_amount');
            $table->bigInteger('total_amount');
            $table->bigInteger('placed_total_amount');
            $table->bigInteger('refunded_amount');
            $table->unsignedInteger('refunds');
            $table->unsignedInteger('cod_orders');
            $table->unsignedInteger('cod_confirmed');
            $table->unsignedInteger('cod_unreachable');
            $table->unsignedInteger('cod_fake');
            $table->unsignedInteger('cod_returned');
            $table->jsonb('payment_methods')->default('{}');
            $table->timestampTz('rebuilt_at');

            $table->unique(['date', 'currency']);
        });

        Schema::create('report_product_days', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->char('currency', 3);
            $table->char('product_ulid', 26);
            $table->string('title');
            $table->unsignedInteger('units');
            $table->bigInteger('revenue_amount');
            $table->unsignedInteger('orders');

            $table->unique(['date', 'currency', 'product_ulid']);
            $table->index(['currency', 'date']);
        });

        // Watermarks for incremental refreshes.
        Schema::create('report_cursors', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->timestampTz('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_cursors');
        Schema::dropIfExists('report_product_days');
        Schema::dropIfExists('report_sales_days');
    }
};
