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
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement('CREATE UNIQUE INDEX brands_slug_live_unique ON brands (slug) WHERE deleted_at IS NULL');

        // Unlimited depth through a closure table (ADR-0006).
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['parent_id', 'position']);
        });
        DB::statement('CREATE UNIQUE INDEX categories_slug_live_unique ON categories (slug) WHERE deleted_at IS NULL');

        Schema::create('category_closure', function (Blueprint $table): void {
            $table->foreignId('ancestor_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('descendant_id')->constrained('categories')->cascadeOnDelete();
            $table->unsignedSmallInteger('depth');

            $table->primary(['ancestor_id', 'descendant_id']);
            $table->index(['descendant_id', 'depth']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('short_description', 500)->nullable();
            $table->string('status')->default('draft');
            $table->jsonb('tags')->default('[]');
            // Free-form specifications (material, dimensions...). Not `attributes`, which is
            // Eloquent's own internal property name.
            $table->jsonb('specifications')->default('{}');
            $table->timestampTz('published_at')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['status', 'published_at']);
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'active', 'archived'))");
        DB::statement('CREATE UNIQUE INDEX products_slug_live_unique ON products (slug) WHERE deleted_at IS NULL');

        Schema::create('product_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['product_id', 'name']);
        });

        Schema::create('product_option_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_option_id')->constrained('product_options')->cascadeOnDelete();
            $table->string('value');
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['product_option_id', 'value']);
        });

        // Every sellable item is a variant; SKU, price, weight, barcode and stock live only here.
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('sku', 64);
            $table->string('barcode', 64)->nullable();
            $table->string('title')->nullable();
            // Signature of the chosen option values, e.g. "color=red|size=m"; empty for the
            // default variant of a simple product. Unique per product: no duplicate variants.
            $table->string('option_signature', 500)->default('');
            $table->bigInteger('price_amount');
            $table->char('price_currency', 3);
            $table->bigInteger('compare_at_price_amount')->nullable();
            $table->char('compare_at_price_currency', 3)->nullable();
            // Weighted-average cost, maintained by Inventory on goods receipt.
            $table->bigInteger('cost_amount')->nullable();
            $table->char('cost_currency', 3)->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->boolean('allow_backorder')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement('CREATE UNIQUE INDEX product_variants_sku_live_unique ON product_variants (lower(sku)) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX product_variants_signature_unique ON product_variants (product_id, option_signature) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX product_variants_one_default ON product_variants (product_id) WHERE is_default AND deleted_at IS NULL');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_price_check CHECK (price_amount >= 0)');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_compare_at_check CHECK (compare_at_price_amount IS NULL OR compare_at_price_amount >= 0)');

        Schema::create('variant_option_values', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('product_option_value_id')->constrained('product_option_values')->restrictOnDelete();

            $table->primary(['product_variant_id', 'product_option_value_id']);
        });

        Schema::create('product_category', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->boolean('is_primary')->default(false);

            $table->primary(['product_id', 'category_id']);
            $table->index('category_id');
        });

        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_dynamic')->default(false);
            $table->jsonb('rules')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement('CREATE UNIQUE INDEX collections_slug_live_unique ON collections (slug) WHERE deleted_at IS NULL');

        Schema::create('collection_product', function (Blueprint $table): void {
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('position')->default(0);

            $table->primary(['collection_id', 'product_id']);
        });
    }

    public function down(): void
    {
        foreach (['collection_product', 'collections', 'product_category', 'variant_option_values', 'product_variants',
            'product_option_values', 'product_options', 'products', 'category_closure', 'categories', 'brands'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
