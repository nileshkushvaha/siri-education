<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instructor withdrawal requests — creating one only reserves earnings;
 * it never moves money on its own. One request = one currency; the
 * payment destination is captured once at creation into
 * `encrypted_payout_method_snapshot` and never regenerated from the
 * (mutable/disable-able) payout method. Money is integer minor units
 * only. `processing`/`paid`/`failed` columns are written by
 * InstructorPayoutExecutionService once a request is approved and
 * queued for execution. No settlement_batch_id: settlement batches
 * settle earnings directly and are semantically parallel to
 * withdrawals — the two mechanisms remain unlinked. Never hard-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_withdrawal_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 32)->unique();
            $table->foreignId('instructor_id')->constrained('users');
            $table->foreignUuid('payout_method_id')->constrained('instructor_payout_methods');
            $table->foreignId('currency_id')->nullable()->constrained('currencies');
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->unsignedBigInteger('net_amount_minor');
            $table->unsignedBigInteger('available_balance_before_minor');
            $table->unsignedBigInteger('available_balance_after_minor');
            $table->string('payout_method_type', 32);
            $table->string('payout_method_label');
            $table->string('masked_identifier', 64);
            $table->longText('encrypted_payout_method_snapshot');
            $table->string('status', 32)->default('submitted');
            $table->uuid('idempotency_key')->nullable();
            $table->string('instructor_note', 1000)->nullable();
            $table->string('internal_review_note', 1000)->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('review_started_at')->nullable();
            $table->foreignId('review_started_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 1000)->nullable();
            $table->timestamps();

            $table->unique(['instructor_id', 'idempotency_key'], 'iwr_instructor_idempotency_unique');
            $table->index(['instructor_id', 'status'], 'iwr_instructor_status_index');
            $table->index(['currency_code', 'status'], 'iwr_currency_status_index');
            $table->index('status');
            $table->index('requested_at');
            $table->index('payout_method_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_withdrawal_requests');
    }
};
