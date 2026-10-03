<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The link to a multi-brand owner identity; there are no brands any more.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('landlord_owner_identity_ulid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->char('landlord_owner_identity_ulid', 26)->nullable();
        });
    }
};
