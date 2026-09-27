<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The brand's own wording for each transactional message.
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('subject');
            $table->text('intro');
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();
        });

        // One row per message actually sent, keyed by the outbox message, so a redelivered
        // outbox message never emails the customer twice.
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->char('outbox_message_ulid', 26)->unique();
            $table->string('template_key', 64);
            $table->char('order_ulid', 26)->nullable()->index();
            $table->string('channel', 16)->default('mail');
            $table->timestampTz('sent_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_templates');
    }
};
