<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group');
            $table->string('key');
            // Plain jsonb; for encrypted settings the value is the ciphertext as a JSON
            // string, so the raw column never holds a readable secret.
            $table->jsonb('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestampsTz();

            $table->unique(['group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
