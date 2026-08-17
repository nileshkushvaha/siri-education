<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wallet recharge cutover — `wallet_recharges` stops being a second
 * record of an external payment.
 *
 * BEFORE, one Razorpay charge was described twice: once on `payments`
 * (for every other payable) and once on `wallet_recharges`, which
 * carried its own `provider`, `provider_order_id` and
 * `provider_payment_id` and drove RazorpayGatewayClient directly. Two
 * independent identities for the same money can disagree about whether
 * it arrived, and two settlement paths can disagree about whether it
 * has been applied. That is the defect being removed, not merely tidied.
 *
 * AFTER:
 *
 *     Payment         external identity + provider lifecycle
 *     WalletRecharge  wallet-domain intent + CREDIT lifecycle
 *
 * The link is `payments.payable_type = 'wallet_recharge'` /
 * `payable_id`, the same polymorphic pair package purchase and booking
 * payment already use. No foreign key is possible on that pair (it
 * spans domains — see the create_payments_table migration); ownership is
 * enforced at the service boundary, consistent with the existing
 * payables.
 *
 * ── What is REMOVED and why ──────────────────────────────────────────
 *   provider, provider_order_id, provider_payment_id
 *       Provider identity. Now exclusively on `payments`, under its
 *       UNIQUE(provider, provider_order_id) / (provider,
 *       provider_payment_id) indexes.
 *   provider_confirmed_at
 *       "The provider told us it captured" — that is `payments.paid_at`.
 *   last_synced_at
 *       "When we last polled the provider" — polling is now the generic
 *       payment reconciliation's job, recorded on `payments.last_synced_at`.
 *       Keeping a wallet copy would imply a second poller.
 *
 * ── What is KEPT and why ─────────────────────────────────────────────
 *   wallet_id, user_id, amount_minor, currency_code
 *       The wallet-domain request. amount/currency are retained
 *       deliberately rather than read through the payment: they are the
 *       DOMAIN SNAPSHOT the settlement path validates the provider's
 *       reported figures against, so a payment row that disagrees with
 *       the recharge is detectable at all.
 *   status, failure_code, failure_reason, succeeded_at, failed_at
 *       The CREDIT lifecycle, which is not the payment lifecycle.
 *       A captured payment whose wallet credit fails (frozen/closed
 *       wallet) must remain durable and retryable — see
 *       WalletRechargeStatus.
 *
 * ── idempotency_key -> reference ─────────────────────────────────────
 * The `WRCH-…` value was never an idempotency key in the sense the rest
 * of the codebase uses that name; it is the recharge's human-traceable
 * business reference (SRS §13.9 "transaction reference number"), and it
 * is passed to PaymentService as the attempt's idempotency key, where
 * that meaning genuinely applies. Renaming it removes the ambiguity of
 * one column meaning two things in two tables. It stays UNIQUE — the
 * reference is how a student, an operator, and a provider's order notes
 * all name the same recharge.
 *
 * ── No legacy compatibility ──────────────────────────────────────────
 * Pre-production, per the cutover brief: no shadow columns, no fallback
 * reads, no "legacy provider id" accessors. Development rows that
 * already have provider identity are NOT back-filled into `payments` —
 * a synthesised attempt would be a fabricated financial record, which is
 * worse than an orphan. Instead, non-terminal development recharges are
 * closed as `cancelled` below so nothing is left in a state whose
 * provider identity has been deleted while it still expects to settle.
 * Succeeded recharges keep their ledger entries and history untouched;
 * their money already moved and the ledger, not this table, is the
 * record of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The CHECK must be widened BEFORE any status is rewritten, or
        // the remap below violates the constraint still in force.
        DB::statement('ALTER TABLE wallet_recharges DROP CONSTRAINT chk_wallet_recharges_status');

        // A recharge still expecting to settle would, after this
        // migration, have no way to reach its provider order. Close
        // those first — deliberately BEFORE the columns disappear, so
        // the reason is recorded while the evidence still exists.
        DB::table('wallet_recharges')
            ->whereIn('status', ['pending', 'provider_created', 'awaiting_confirmation'])
            ->update([
                'status' => 'cancelled',
                'failure_code' => 'provider_identity_cutover',
                'failure_reason' => 'Closed by the generic payment ledger cutover; no wallet was credited.',
                'failed_at' => now(),
            ]);

        // `requested` replaces the old provider-shaped opening states.
        // Nothing should still hold them after the sweep above; this is
        // belt-and-braces for a row inserted mid-migration.
        DB::table('wallet_recharges')
            ->whereIn('status', ['pending', 'provider_created', 'awaiting_confirmation'])
            ->update(['status' => 'requested']);

        DB::statement("ALTER TABLE wallet_recharges ADD CONSTRAINT chk_wallet_recharges_status CHECK (status IN ('requested', 'credit_pending', 'succeeded', 'failed', 'credit_failed', 'cancelled', 'expired'))");

        Schema::table('wallet_recharges', function (Blueprint $table): void {
            // Dropped explicitly: MySQL will not drop a column that a
            // unique index still covers.
            $table->dropUnique('wallet_recharges_provider_order_id_unique');
            $table->dropUnique('wallet_recharges_provider_payment_id_unique');
        });

        Schema::table('wallet_recharges', function (Blueprint $table): void {
            $table->dropColumn([
                'provider',
                'provider_order_id',
                'provider_payment_id',
                'provider_confirmed_at',
                'last_synced_at',
            ]);
        });

        Schema::table('wallet_recharges', function (Blueprint $table): void {
            $table->renameColumn('idempotency_key', 'reference');
        });

        // `(status, last_synced_at)` served the old wallet-owned
        // provider sweep, whose driving column no longer exists. The
        // remaining wallet-domain sweep asks only "which credits are
        // unfinished", which `(user_id, status)` and `(wallet_id,
        // status)` already serve.
        Schema::table('wallet_recharges', function (Blueprint $table): void {
            $table->dropIndex('wallet_recharges_status_last_synced_at_index');
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE wallet_recharges DROP CONSTRAINT chk_wallet_recharges_status');
        DB::table('wallet_recharges')->where('status', 'requested')->update(['status' => 'pending']);
        DB::statement("ALTER TABLE wallet_recharges ADD CONSTRAINT chk_wallet_recharges_status CHECK (status IN ('pending', 'provider_created', 'awaiting_confirmation', 'credit_pending', 'succeeded', 'failed', 'credit_failed', 'cancelled', 'expired'))");

        Schema::table('wallet_recharges', function (Blueprint $table): void {
            $table->renameColumn('reference', 'idempotency_key');
        });

        Schema::table('wallet_recharges', function (Blueprint $table): void {
            $table->string('provider', 30)->default('razorpay');
            $table->string('provider_order_id', 100)->nullable();
            $table->string('provider_payment_id', 100)->nullable();
            $table->timestamp('provider_confirmed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->unique('provider_order_id');
            $table->unique('provider_payment_id');
            $table->index(['status', 'last_synced_at']);
        });
    }
};
