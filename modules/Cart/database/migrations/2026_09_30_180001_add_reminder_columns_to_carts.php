<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Abandoned-cart reminders: the email given at checkout, when the one reminder went out,
        // and when its link brought the cart back.
        Schema::table('carts', function (Blueprint $table): void {
            $table->string('email')->nullable();
            $table->timestampTz('reminded_at')->nullable();
            $table->timestampTz('recovered_at')->nullable();

            $table->index(['status', 'reminded_at', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropIndex(['status', 'reminded_at', 'updated_at']);
            $table->dropColumn(['email', 'reminded_at', 'recovered_at']);
        });
    }
};
