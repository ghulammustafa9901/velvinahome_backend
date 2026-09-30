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
        // Product reviews by customers who received the product; published only after
        // moderation (spec §5 Reviews).
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->char('product_ulid', 26);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->char('order_ulid', 26)->nullable();
            $table->string('author_name', 100);
            $table->unsignedSmallInteger('rating');
            $table->string('title', 150)->nullable();
            $table->text('body');
            $table->string('status', 16)->default('pending');
            $table->timestampTz('moderated_at')->nullable();
            $table->char('moderator_ulid', 26)->nullable();
            $table->timestampsTz();

            $table->unique(['product_ulid', 'customer_id']);
            $table->index(['product_ulid', 'status', 'created_at']);
        });

        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
