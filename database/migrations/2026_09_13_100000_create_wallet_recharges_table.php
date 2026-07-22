<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per gateway wallet-recharge attempt. Deliberately separate
 * from booking_payments, which is booking-specific by construction
 * (booking_id is NOT NULL there) — a recharge has no booking. The
 * wallet's own ledger credit is a WalletLedgerEntry linked back here
 * via source_type/source_id, never a foreign key on this table.
 * Nothing here ever stores a raw webhook body, signature, or
 * payment-method detail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_recharges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->unsignedBigInteger('user_id');

            $table->string('provider', 30);
            $table->string('provider_order_id', 100)->nullable();
            $table->string('provider_payment_id', 100)->nullable();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('status', 30);
            $table->string('idempotency_key', 100);
            $table->string('failure_code', 60)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('provider_confirmed_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('wallet_id')->references('id')->on('wallets')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique('idempotency_key');
            $table->unique('provider_order_id');
            $table->unique('provider_payment_id');
            $table->index(['wallet_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'last_synced_at']);
        });

        DB::statement('ALTER TABLE wallet_recharges ADD CONSTRAINT chk_wallet_recharges_amount_positive CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE wallet_recharges ADD CONSTRAINT chk_wallet_recharges_status CHECK (status IN ('pending', 'provider_created', 'awaiting_confirmation', 'credit_pending', 'succeeded', 'failed', 'credit_failed', 'cancelled', 'expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_recharges');
    }
};
