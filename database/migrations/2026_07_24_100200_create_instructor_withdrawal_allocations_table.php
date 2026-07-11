<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservation ledger between withdrawal requests and Phase 14 earnings.
 * Phase 14 has no reservation concept (settlement_batch_id is a whole-
 * row assignment), so this table is the canonical reservation record:
 * an earning's withdrawable remainder = earning_amount_minor − SUM of
 * its non-released allocations. Partial amounts are supported (FIFO
 * allocation may split the last earning). Rows are never hard-deleted;
 * rejected/cancelled withdrawals flip them to `released`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_withdrawal_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('withdrawal_request_id')->constrained('instructor_withdrawal_requests');
            $table->foreignUuid('instructor_earning_id')->constrained('instructor_earnings');
            $table->foreignId('currency_id')->nullable()->constrained('currencies');
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 16)->default('reserved');
            $table->timestamp('reserved_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique(['withdrawal_request_id', 'instructor_earning_id'], 'iwa_request_earning_unique');
            $table->index(['instructor_earning_id', 'status'], 'iwa_earning_status_index');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_withdrawal_allocations');
    }
};
