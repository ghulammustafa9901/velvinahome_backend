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
        // The store's payment methods and merchant credentials (encrypted); funds always settle
        // to the store's own account (spec §6.6).
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('code', 32)->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->jsonb('config')->default('{}');
            $table->text('credentials')->nullable();
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });

        // One row per attempt to pay an order; state separate from the order's (spec §6.6).
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->char('order_ulid', 26)->index();
            $table->string('method_code', 32);
            $table->string('status', 24);
            $table->bigInteger('amount_amount');
            $table->char('amount_currency', 3);
            $table->bigInteger('paid_amount')->default(0);
            $table->bigInteger('refunded_amount')->default(0);
            $table->string('gateway_reference')->nullable();
            // Manual transfer: the customer's receipt, on the private disk.
            $table->string('proof_path')->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending', 'awaiting_verification', 'paid', 'failed', 'cancelled', 'partially_refunded', 'refunded'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amounts_check CHECK (amount_amount >= 0 AND paid_amount >= 0 AND refunded_amount >= 0 AND refunded_amount <= paid_amount)');

        // Append-only ledger of money movements (UPDATE/DELETE revoked from the app role).
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('type', 16);
            $table->bigInteger('amount_amount');
            $table->char('amount_currency', 3);
            $table->string('reference')->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->text('note')->nullable();
            $table->char('actor_ulid', 26)->nullable();
            $table->jsonb('gateway_response')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_type_check CHECK (type IN ('authorize', 'capture', 'refund', 'void'))");
        DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_amount_check CHECK (amount_amount >= 0)');

        // Every inbound webhook, stored raw and deduplicated by the provider's event id.
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('provider', 32);
            $table->string('provider_event_id');
            $table->boolean('signature_verified');
            $table->jsonb('payload');
            $table->jsonb('headers')->nullable();
            $table->timestampTz('received_at')->useCurrent();

            $table->unique(['provider', 'provider_event_id']);
        });

        // Processing outcomes live apart, so `webhook_events` itself stays append-only.
        Schema::create('webhook_event_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webhook_event_id')->constrained('webhook_events')->restrictOnDelete();
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['webhook_event_outcomes', 'webhook_events', 'payment_transactions', 'payments', 'payment_methods'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
