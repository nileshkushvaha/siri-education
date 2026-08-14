<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4B.2 — the student's accepted commercial purchase, sitting
 * between the proposal (a negotiated offer) and the entitlement (a
 * usable lesson balance). Acceptance now creates one of these instead
 * of activating an entitlement directly; the entitlement is created
 * only after verified settlement in Phase 4B.3.
 *
 * Deliberately carries NO gateway state. `provider`,
 * `provider_order_id`, `provider_payment_id`, `idempotency_key`,
 * `failure_code`, and `failure_message` all live on `payments`, one row
 * per attempt. A purchase has many attempts, so storing any of them
 * here would immediately be wrong the moment a student retries — and
 * would duplicate state the generic payment layer already owns.
 *
 * `UNIQUE(proposal_id)` is the DB-level guarantee that one proposal can
 * only ever produce one purchase: a failed payment means a new Payment
 * attempt against the SAME purchase, never a second purchase. It also
 * mirrors, and stacks with, the identical unique index on
 * `student_package_entitlements.proposal_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_package_purchases', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // One purchase per proposal, forever.
            $table->uuid('proposal_id')->unique();
            $table->unsignedBigInteger('student_id');

            // Human-facing, support-quotable, and safe to send to a
            // gateway as a receipt/metadata value.
            $table->string('reference', 32)->unique();

            // Snapshot of the terms the student actually accepted —
            // never re-resolved from the pricing matrix afterwards.
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->char('currency_code', 3);

            // pending_payment | paid — App\Package\Enums\PackagePurchaseStatus
            $table->string('status', 20)->default('pending_payment');

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            // Commercial history — never cascade a purchase away.
            $table->foreign('proposal_id')->references('id')->on('instructor_package_proposals')->restrictOnDelete();
            $table->foreign('student_id')->references('id')->on('users')->restrictOnDelete();
            // The currency master may be retired without destroying the
            // record of what was charged — `currency_code` is the
            // denormalized, authoritative snapshot.
            $table->foreign('currency_id')->references('id')->on('currencies')->nullOnDelete();

            $table->index('student_id');
            $table->index('status');
        });

        DB::statement('ALTER TABLE student_package_purchases ADD CONSTRAINT chk_student_package_purchases_amount CHECK (amount_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('student_package_purchases');
    }
};
