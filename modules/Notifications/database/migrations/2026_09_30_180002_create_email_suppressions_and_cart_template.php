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
        // Addresses that asked for no more non-essential email (cart reminders). Stored as a
        // SHA-256 of the lower-cased address: enough to check, nothing to leak.
        Schema::create('email_suppressions', function (Blueprint $table): void {
            $table->id();
            $table->char('email_hash', 64)->unique();
            $table->string('reason', 32);
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->char('cart_ulid', 26)->nullable();
        });

        // Brands created before this template existed get it too (switched on; the feature
        // flag decides whether reminders go out at all).
        if (! DB::table('notification_templates')->where('key', 'cart.abandoned')->exists()) {
            DB::table('notification_templates')->insert([
                'key' => 'cart.abandoned',
                'subject' => 'You left something in your cart',
                'intro' => 'Hi, your cart at {{ store_name }} is saved. Pick up where you left off — items sell out.',
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', 'cart.abandoned')->delete();

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropColumn('cart_ulid');
        });

        Schema::dropIfExists('email_suppressions');
    }
};
