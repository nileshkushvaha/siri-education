<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Models\Payment;
use App\Models\StudentPackagePurchase;
use App\Package\Enums\PackagePurchaseStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Exceptions\PaymentAttemptAlreadyOpenException;
use App\Payments\Services\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4E.2 — the open-attempt invariant behind PKG-AUD-004 (Race A).
 *
 * The property is deliberately asserted at the DATABASE level, not only
 * through the service: the original bug was a read-then-insert that took
 * no lock, so any test that only exercises the happy service path would
 * have passed against the broken code too. Several tests here therefore
 * bypass PaymentService entirely and try to force a forged second open
 * attempt straight into the table.
 */
class PaymentAttemptConcurrencyInvariantTest extends TestCase
{
    use RefreshDatabase;

    private function purchaseId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * A pending purchase, built directly — the proposal/settlement
     * pipeline has its own suite and is not what these tests claim.
     */
    private function purchase(int $amountMinor = 49900): StudentPackagePurchase
    {
        Schema::disableForeignKeyConstraints();

        $purchase = StudentPackagePurchase::query()->create([
            'proposal_id' => (string) Str::uuid(),
            'student_id' => 1,
            'reference' => 'PKG-'.strtoupper(Str::random(12)),
            'amount_minor' => $amountMinor,
            'currency_code' => 'INR',
            'status' => PackagePurchaseStatus::PendingPayment,
            'accepted_at' => now(),
        ]);

        Schema::enableForeignKeyConstraints();

        return $purchase;
    }

    /** A raw attempt row, bypassing the service so the DB invariant is what is under test. */
    private function forceAttempt(string $payableId, PaymentStatus $status, ?string $orderId = null): Payment
    {
        Schema::disableForeignKeyConstraints();

        $payment = Payment::query()->create([
            'payable_type' => StudentPackagePurchase::PAYABLE_TYPE,
            'payable_id' => $payableId,
            'provider' => 'razorpay',
            'provider_order_id' => $orderId,
            'amount_minor' => 49900,
            'currency_code' => 'INR',
            'status' => $status,
            'idempotency_key' => 'PAY-'.strtoupper(Str::random(16)),
        ]);

        Schema::enableForeignKeyConstraints();

        return $payment;
    }

    // ── 1-3. The core invariant ───────────────────────────────────────────

    public function test_a_payable_may_accumulate_many_terminal_attempts(): void
    {
        $payable = $this->purchaseId();

        $this->forceAttempt($payable, PaymentStatus::Failed);
        $this->forceAttempt($payable, PaymentStatus::Cancelled);
        $this->forceAttempt($payable, PaymentStatus::Failed);

        // Financial history is append-only — the fix must not have
        // collapsed retries into one mutable row (spec Part 8).
        $this->assertSame(3, Payment::query()->where('payable_id', $payable)->count());
    }

    public function test_the_database_refuses_a_second_open_attempt(): void
    {
        $payable = $this->purchaseId();
        $this->forceAttempt($payable, PaymentStatus::Pending);

        $this->expectException(QueryException::class);

        // Forged directly against the table: even with every application
        // guard bypassed, a second open attempt must be impossible.
        $this->forceAttempt($payable, PaymentStatus::Pending);
    }

    public function test_processing_also_counts_as_open(): void
    {
        $payable = $this->purchaseId();
        $this->forceAttempt($payable, PaymentStatus::Processing);

        $this->expectException(QueryException::class);

        $this->forceAttempt($payable, PaymentStatus::Pending);
    }

    // ── 4-6. Retry remains possible ───────────────────────────────────────

    public function test_a_failed_attempt_permits_a_new_open_attempt(): void
    {
        $payable = $this->purchaseId();
        $this->forceAttempt($payable, PaymentStatus::Failed);

        $retry = $this->forceAttempt($payable, PaymentStatus::Pending);

        // The invariant must not have broken legitimate retry
        // (stop condition 5).
        $this->assertSame(PaymentStatus::Pending, $retry->status);
        $this->assertSame(2, Payment::query()->where('payable_id', $payable)->count());
    }

    public function test_a_cancelled_attempt_permits_a_new_open_attempt(): void
    {
        $payable = $this->purchaseId();
        $this->forceAttempt($payable, PaymentStatus::Cancelled);

        $retry = $this->forceAttempt($payable, PaymentStatus::Pending);

        $this->assertSame(PaymentStatus::Pending, $retry->status);
    }

    public function test_an_open_attempt_becoming_terminal_frees_the_slot(): void
    {
        $payable = $this->purchaseId();
        $open = $this->forceAttempt($payable, PaymentStatus::Pending);

        $open->fill(['status' => PaymentStatus::Failed, 'failed_at' => now()])->save();

        $next = $this->forceAttempt($payable, PaymentStatus::Pending);

        $this->assertSame(PaymentStatus::Pending, $next->status);
    }

    // ── 7-8. Scoping ──────────────────────────────────────────────────────

    public function test_the_invariant_is_scoped_per_payable_not_globally(): void
    {
        $first = $this->forceAttempt($this->purchaseId(), PaymentStatus::Pending);
        $second = $this->forceAttempt($this->purchaseId(), PaymentStatus::Pending);

        // Two different purchases must each be able to hold their own
        // open attempt — a global unique index would have deadlocked the
        // entire platform's checkout.
        $this->assertNotSame($first->payable_id, $second->payable_id);
        $this->assertSame(2, Payment::query()->where('status', PaymentStatus::Pending)->count());
    }

    public function test_the_open_attempt_marker_is_generated_by_the_database(): void
    {
        $payable = $this->purchaseId();
        $payment = $this->forceAttempt($payable, PaymentStatus::Pending);

        $marker = fn (): ?int => DB::table('payments')->where('id', $payment->id)->value('open_attempt_marker');

        $this->assertSame(1, (int) $marker());

        $payment->fill(['status' => PaymentStatus::Paid, 'paid_at' => now()])->save();

        // Derived state belongs to the database — nothing in PHP writes
        // this column, so it cannot drift from status.
        $this->assertNull($marker());
    }

    public function test_the_generated_marker_matches_the_enums_own_definition_of_open(): void
    {
        // The migration duplicates the open-status list in SQL because
        // SQL cannot call PHP. This pins the two together so adding an
        // enum case cannot silently desynchronize them.
        $payable = $this->purchaseId();

        foreach (PaymentStatus::cases() as $status) {
            $payment = $this->forceAttempt($payable, $status);
            $marker = DB::table('payments')->where('id', $payment->id)->value('open_attempt_marker');

            $this->assertSame(
                $status->isOpen(),
                $marker !== null,
                sprintf('PaymentStatus::%s disagrees with the generated open_attempt_marker.', $status->name),
            );

            // Free the slot for the next iteration.
            DB::table('payments')->where('id', $payment->id)->delete();
        }
    }

    // ── Service behaviour ─────────────────────────────────────────────────

    public function test_the_service_hands_back_the_winning_attempt_rather_than_a_bare_failure(): void
    {
        $purchase = $this->purchase();
        $existing = $this->forceAttempt((string) $purchase->id, PaymentStatus::Pending);

        try {
            app(PaymentService::class)->startAttempt($purchase, 'razorpay', 'PAY-'.strtoupper(Str::random(16)));
            $this->fail('A second open attempt should have been refused.');
        } catch (PaymentAttemptAlreadyOpenException $e) {
            // Carrying the winner lets checkout converge on the same
            // gateway order instead of erroring (spec Part 4).
            $this->assertSame($existing->id, $e->attempt->id);
        }
    }

    public function test_initialization_can_be_claimed_exactly_once(): void
    {
        $payment = $this->forceAttempt($this->purchaseId(), PaymentStatus::Pending);
        $service = app(PaymentService::class);

        $this->assertTrue($service->claimInitialization($payment));
        // The second caller must not talk to the provider (Race B).
        $this->assertFalse($service->claimInitialization($payment->refresh()));
    }

    public function test_an_attempt_that_already_has_an_order_cannot_be_claimed(): void
    {
        $payment = $this->forceAttempt($this->purchaseId(), PaymentStatus::Pending, orderId: 'order_existing');

        $this->assertFalse(app(PaymentService::class)->claimInitialization($payment));
    }
}
