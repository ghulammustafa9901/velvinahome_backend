<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One outstanding reset per customer; only the SHA-256 of the emailed token is kept.
        Schema::create('customer_password_resets', function (Blueprint $table): void {
            $table->foreignId('customer_id')->primary()->constrained('customers')->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_password_resets');
    }
};
