<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The automatic promotions an order was placed with: [{name, amount}] in minor units,
        // part of `discount_amount` (the line snapshots carry the per-line split).
        Schema::table('orders', function (Blueprint $table): void {
            $table->jsonb('promotions')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('promotions');
        });
    }
};
