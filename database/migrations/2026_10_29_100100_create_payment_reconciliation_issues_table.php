<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4E.2 — closes PKG-AUD-014: a verified provider event whose
 * money does not match ours currently refuses to settle (correctly)
 * and then writes one activity-log line nobody is watching. The
 * provider may hold real money while the platform shows a package
 * stuck at pending_payment and no operator is told.
 *
 * GENERIC, NOT PACKAGE-SPECIFIC. Packages are merely the first
 * consumer of the new App\Payments architecture; the discrepancy is a
 * property of a generic `payments` attempt, so this hangs off
 * `payment_id` rather than off a purchase. A future Payable gets the
 * queue for free.
 *
 * TRANSITIONAL, exactly like `payments` itself. This does NOT replace
 * and does NOT migrate `booking_payment_reconciliation_issues`, which
 * keeps serving the legacy booking pipeline with its own richer
 * severity/type taxonomy. Two queues coexisting is the same conscious
 * trade already documented for the payment records themselves — see
 * docs/generic-payable-payment-foundation.md. What is deliberately NOT
 * duplicated is the settlement logic that detects the discrepancy:
 * there is one detector, called by both the webhook and the sweep.
 *
 * DEDUPLICATION is a database invariant, not a service convention.
 * A provider that redelivers the same mismatching webhook fifty times
 * must produce ONE operator row with occurrence_count = 50, never fifty
 * rows. `open_issue_marker` is a STORED GENERATED column holding 1
 * while the issue is open and NULL once resolved, so
 * UNIQUE(payment_id, issue_type, open_issue_marker) allows exactly one
 * OPEN issue of a type per attempt while keeping every resolved one as
 * history. Same NULL-distinctness technique as
 * payments.open_attempt_marker, for the same reason: repeated delivery
 * is concurrent, and a check-then-insert would race.
 *
 * EVIDENCE IS NORMALIZED AND SAFE. Amounts and currency codes only —
 * never a raw webhook body, never a signature, never a credential,
 * never card/UPI data. That is the same stance booking_payments,
 * wallet_recharges and payments.metadata already take, and it is why
 * this table can be shown to an operator at all.
 *
 * RESOLUTION NEVER MOVES MONEY. Nothing here can mark a payment or a
 * purchase paid; the columns describe an operational record only.
 * Settlement remains reachable exclusively through verified provider
 * evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliation_issues', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('payment_id');
            $table->string('provider', 30);

            // amount_mismatch | currency_mismatch —
            // App\Payments\Enums\PaymentReconciliationIssueType
            $table->string('issue_type', 40);
            // open | resolved —
            // App\Payments\Enums\PaymentReconciliationIssueStatus
            $table->string('status', 20)->default('open');

            // What we believed was owed, versus what the provider
            // reported. Nullable because a currency discrepancy carries
            // no meaningful amount evidence and vice versa.
            $table->unsignedBigInteger('expected_amount_minor')->nullable();
            $table->unsignedBigInteger('observed_amount_minor')->nullable();
            $table->char('expected_currency', 3)->nullable();
            $table->char('observed_currency', 3)->nullable();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('occurrence_count')->default(1);

            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('resolution_note')->nullable();

            // Non-sensitive context only (detection source, purchase
            // reference). Never credentials, signatures, or raw payloads.
            $table->json('metadata')->nullable();

            $table->timestamps();

            // An issue is evidence about a payment attempt — the attempt
            // must never vanish beneath it.
            $table->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
            $table->index('issue_type');
            $table->index('provider');
            $table->index('last_seen_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_reconciliation_issues
            ADD COLUMN open_issue_marker TINYINT UNSIGNED
            GENERATED ALWAYS AS (
                CASE WHEN status = 'open' THEN 1 ELSE NULL END
            ) STORED
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payment_reconciliation_issues
            ADD CONSTRAINT payment_reconciliation_issues_one_open_per_type
            UNIQUE (payment_id, issue_type, open_issue_marker)
        SQL);

        DB::statement('ALTER TABLE payment_reconciliation_issues ADD CONSTRAINT chk_payment_reconciliation_issues_occurrence CHECK (occurrence_count >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_issues');
    }
};
