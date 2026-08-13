<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4B.1 — the generic payment-attempt record for NEW payment
 * consumers (App\Payments\Contracts\Payable).
 *
 * TRANSITIONAL BY DESIGN. This table does not replace and does not
 * migrate `booking_payments` or `wallet_recharges`; both legacy flows
 * are deliberately untouched (see docs/architecture/payment-domain.md).
 * What is NOT duplicated is the expensive part: every payment path in
 * this application — legacy and generic — shares the same
 * RazorpayGatewayClient / StripeGatewayClient / PaymentWebhookSignatureService.
 *
 * ONE ROW = ONE ATTEMPT. A Payable may accumulate many attempts (a
 * failed Razorpay order followed by a successful one). Retrying never
 * overwrites a previous attempt — financial history is append-only
 * here.
 *
 * `payable_type`/`payable_id` follow the wallet_ledger_entries
 * precedent (plain string columns, composite index) rather than
 * Laravel's morphs() helper, because the payable set spans domains and
 * MySQL cannot foreign-key a polymorphic pair to several tables.
 * Payable existence and ownership are therefore enforced at the service
 * boundary (PaymentService), never by the schema — that is a conscious
 * trade, not an oversight, and is why no FK exists on those columns.
 * `payable_type` stores a stable morph ALIAS (e.g. `package_purchase`),
 * never a PHP FQCN, so class/namespace moves cannot orphan rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('payable_type', 50);
            $table->string('payable_id', 60);

            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('provider', 30);
            $table->string('provider_order_id', 100)->nullable();
            $table->string('provider_payment_id', 100)->nullable();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);

            // pending | processing | paid | failed | cancelled — App\Payments\Enums\PaymentStatus
            $table->string('status', 20)->default('pending');

            $table->string('idempotency_key', 100)->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->string('failure_message', 255)->nullable();
            // Non-sensitive context only — never credentials, card/UPI
            // details, or raw provider signatures (matches the
            // BookingPayment/WalletRecharge stance).
            $table->json('metadata')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            // A provider reference identifies exactly one attempt, but
            // the same id string could legitimately recur across
            // different providers — hence scoping both by provider.
            // Nullable columns keep these permissive until a reference
            // is actually issued (MySQL treats NULLs as distinct).
            $table->unique(['provider', 'provider_order_id'], 'payments_provider_order_unique');
            $table->unique(['provider', 'provider_payment_id'], 'payments_provider_payment_unique');
            $table->unique('idempotency_key', 'payments_idempotency_key_unique');

            $table->index(['payable_type', 'payable_id'], 'payments_payable_index');
            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount_positive CHECK (amount_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
