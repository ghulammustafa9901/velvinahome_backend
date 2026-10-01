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
        // Every restore and restore drill, with what was checked (spec §9: the drill is
        // documented and passed, so its evidence is kept).
        Schema::create('tenant_restores', function (Blueprint $table): void {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->foreignId('backup_id')->constrained('tenant_backups')->restrictOnDelete();
            $table->string('mode', 16);
            $table->string('status', 16);
            $table->unsignedInteger('tables_checked')->default(0);
            $table->unsignedBigInteger('rows_checked')->default(0);
            $table->jsonb('differences')->default('[]');
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->char('actor_ulid', 26)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
        });

        DB::statement("ALTER TABLE tenant_restores ADD CONSTRAINT tenant_restores_mode_check CHECK (mode IN ('drill', 'in_place'))");
        DB::statement("ALTER TABLE tenant_restores ADD CONSTRAINT tenant_restores_status_check CHECK (status IN ('running', 'succeeded', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_restores');
    }
};
