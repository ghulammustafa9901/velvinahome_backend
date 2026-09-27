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
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('code', 50);
            $table->string('discount_type', 16);
            // Basis points for percentage coupons, minor units for fixed ones.
            $table->bigInteger('value')->default(0);
            $table->bigInteger('minimum_spend_amount')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            // {"product_ulids": [...], "category_ulids": [...]} — empty means the whole cart.
            $table->jsonb('conditions')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX coupons_code_unique ON coupons (lower(code))');
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_type_check CHECK (discount_type IN ('percentage', 'fixed', 'free_shipping'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_value_check CHECK (value >= 0 AND (discount_type <> 'percentage' OR value <= 10000))");
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_usage_check CHECK (usage_limit IS NULL OR used_count <= usage_limit)');

        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->char('order_ulid', 26);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('email')->nullable();
            $table->bigInteger('discount_amount');
            $table->char('discount_currency', 3);
            $table->timestampTz('voided_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['coupon_id', 'order_ulid']);
            $table->index(['coupon_id', 'customer_id']);
            $table->index(['coupon_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
