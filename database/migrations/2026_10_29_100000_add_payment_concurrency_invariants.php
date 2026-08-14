<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4E.2 — closes PKG-AUD-004, the only genuine double-charge
 * exposure the Phase 4E audit found.
 *
 * TWO independent races existed, and an application-level
 * "SELECT then INSERT" closed neither.
 *
 * ── Race A: two open attempts for one payable ────────────────────────
 * PaymentService::startAttempt() opened a transaction, queried for an
 * open attempt, and inserted when it found none. It took no lock —
 * there was no row to lock — so two concurrent requests both read zero
 * open attempts and both inserted. Two live gateway orders followed.
 *
 * `open_attempt_marker` is a STORED GENERATED column carrying 1 while
 * the attempt is open (pending/processing) and NULL once it is terminal
 * (paid/failed/cancelled). Because MySQL treats NULLs as distinct in a
 * unique index, UNIQUE(payable_type, payable_id, open_attempt_marker)
 * permits UNLIMITED historical terminal attempts while allowing at most
 * ONE open attempt per payable — exactly the invariant, expressed where
 * concurrency cannot argue with it.
 *
 * Deliberately generated rather than maintained by the application: a
 * column the service had to remember to write would be one forgotten
 * transition away from silently allowing a second open attempt. It also
 * mirrors the `student_package_entitlements.remaining_quantity`
 * precedent — derived state belongs to the database.
 *
 * The status list is intentionally duplicated here from
 * PaymentStatus::isTerminal() rather than referenced; SQL cannot call
 * PHP. PaymentStatusInvariantTest pins the two together so adding an
 * enum case cannot silently desynchronize them.
 *
 * ── Race B: two provider orders for ONE attempt ──────────────────────
 * Even with Race A closed, two requests could both read the same
 * attempt with provider_order_id = NULL and each call
 * createOrder()/createPaymentIntent(). One local row, two external
 * orders — still a double charge.
 *
 * `initialization_claimed_at` is the atomic claim: exactly one worker
 * wins a conditional UPDATE ... WHERE provider_order_id IS NULL AND
 * initialization_claimed_at IS NULL, and only that worker talks to the
 * provider. Losers wait for the winner's order id and resume it.
 *
 * WHY NOT REUSE `Processing`: that status already means "the provider
 * reports this payment in flight" — Stripe's payment_intent.processing
 * and Razorpay's attempted both map to it, and
 * PackagePurchaseSettlementService::applyProcessing() writes it from
 * verified webhooks. Overloading it as an initialization claim would
 * make "we are calling the gateway" indistinguishable from "the
 * customer is mid-payment", and would corrupt reconciliation, which
 * treats open attempts as pollable. A dedicated column keeps the
 * lifecycle honest.
 *
 * The claim is deliberately NOT auto-expiring/reclaimable. A claimed
 * attempt that never recorded an order id is AMBIGUOUS — the provider
 * may or may not have created an order we never saw — and silently
 * reclaiming it would risk issuing a second external order against an
 * order that might already exist. Such an attempt is closed as Failed
 * and the student starts a NEW attempt instead (see PaymentService).
 *
 * Additive and reversible. Existing `payments` rows keep their data:
 * the generated column is computed from status on read/backfill, and
 * initialization_claimed_at is simply NULL for historical rows, which
 * correctly reads as "never claimed".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestamp('initialization_claimed_at')->nullable()->after('idempotency_key');
        });

        // Raw DDL: Laravel's storedAs() cannot express a CASE over an
        // existing column list on ALTER for every supported MySQL
        // version, and the generation expression must be exact.
        DB::statement(<<<'SQL'
            ALTER TABLE payments
            ADD COLUMN open_attempt_marker TINYINT UNSIGNED
            GENERATED ALWAYS AS (
                CASE WHEN status IN ('pending', 'processing') THEN 1 ELSE NULL END
            ) STORED
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payments
            ADD CONSTRAINT payments_one_open_attempt_per_payable
            UNIQUE (payable_type, payable_id, open_attempt_marker)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP INDEX payments_one_open_attempt_per_payable');
        DB::statement('ALTER TABLE payments DROP COLUMN open_attempt_marker');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('initialization_claimed_at');
        });
    }
};
