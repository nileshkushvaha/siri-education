<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per gateway payment attempt (a retried/failed booking payment
 * creates a new row, not a mutated one — full attempt history for
 * support/audit). This is not the wallet ledger: it tracks a booking's
 * gateway order/payment lifecycle, never money movement between wallets.
 * provider_signature is intentionally never populated — the verified
 * status/paid_at already prove signature verification happened, and the
 * signature itself has no legitimate use once consumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('booking_id');
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('provider', 30);
            $table->string('provider_order_id', 100)->nullable();
            $table->string('provider_payment_id', 100)->nullable();
            $table->string('provider_signature', 255)->nullable();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('status', 20);
            $table->string('payment_method', 30)->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['booking_id', 'status']);
            $table->unique('provider_order_id');
            $table->unique('provider_payment_id');
            $table->unique('idempotency_key');
            $table->index('status');
        });

        DB::statement('ALTER TABLE booking_payments ADD CONSTRAINT chk_booking_payments_amount_positive CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE booking_payments ADD CONSTRAINT chk_booking_payments_status CHECK (status IN ('pending', 'authorized', 'captured', 'failed', 'cancelled', 'expired', 'refunded'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payments');
    }
};
