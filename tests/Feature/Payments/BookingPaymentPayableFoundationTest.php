<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingPaymentStatus;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Payment;
use App\Models\StudentPackagePurchase;
use App\Models\User;
use App\Payments\Contracts\Payable;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Exceptions\PaymentAttemptAlreadyOpenException;
use App\Payments\Services\PaymentService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PAY-4A — BookingPayment as a Payable.
 *
 * The audit's conclusion was that `BookingPayment` and
 * `StudentPackagePurchase` are the true analogues: both are commercial
 * obligations, both can be attempted many times, both settle once.
 * Generic `Payment` is the attempt ledger underneath either of them.
 *
 * These tests prove BookingPayment can occupy that position — stable
 * polymorphic identity, obligation-snapshot money, preserved failed
 * history, one open attempt, atomic initialization claim — WITHOUT
 * changing anything about how live Booking checkout runs today. The
 * cutover is PAY-4B; this is the foundation it will stand on.
 */
class BookingPaymentPayableFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    /** A gateway-payable booking obligation: money genuinely owed, in a fixed currency. */
    private function obligation(int $amountMinor = 499900, string $currency = 'INR'): BookingPayment
    {
        $student = User::factory()->create(['status' => 'active']);

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'payment_status' => BookingPaymentStatus::Pending,
        ]);

        return BookingPayment::factory()->create([
            'booking_id' => $booking->id,
            'user_id' => $student->id,
            'amount_minor' => $amountMinor,
            'currency_code' => $currency,
            'status' => BookingPaymentRecordStatus::Pending,
        ]);
    }

    // ── Contract mapping (Parts 2–7) ──────────────────────────────────────

    public function test_booking_payment_implements_payable(): void
    {
        $this->assertInstanceOf(Payable::class, $this->obligation());
    }

    /**
     * A booking is a lesson, not a debt. Most bookings — package-funded,
     * free demo, not-required — are never payable at all, so making the
     * lesson the payable would imply every lesson can open a checkout.
     */
    public function test_booking_itself_is_not_payable(): void
    {
        $this->assertNotInstanceOf(Payable::class, Booking::factory()->make());
    }

    public function test_payable_amount_is_the_obligation_snapshot_in_minor_units(): void
    {
        $obligation = $this->obligation(amountMinor: 499900);

        $this->assertSame(499900, $obligation->paymentAmountMinor());
        $this->assertIsInt($obligation->paymentAmountMinor());
    }

    /**
     * The obligation must not be re-priced by anything that happens
     * after it was created. Changing the booking's own price leaves the
     * amount already owed exactly where it was.
     */
    public function test_payable_amount_does_not_follow_later_repricing(): void
    {
        $obligation = $this->obligation(amountMinor: 499900);

        $obligation->booking->update(['price' => 999.99, 'currency' => 'USD']);

        $this->assertSame(499900, $obligation->fresh()->paymentAmountMinor());
        $this->assertSame('INR', $obligation->fresh()->paymentCurrencyCode());
    }

    public function test_payable_currency_is_the_stored_denomination(): void
    {
        $this->assertSame('GBP', $this->obligation(currency: 'GBP')->paymentCurrencyCode());
    }

    public function test_payable_owner_is_the_student_who_owes_the_money(): void
    {
        $obligation = $this->obligation();

        $this->assertSame((int) $obligation->user_id, $obligation->paymentUserId());
    }

    /**
     * Payable methods run in webhooks, the scheduler, and queued
     * reconciliation — contexts with no session at all. Ownership must
     * never be inferred from whoever happens to be logged in.
     */
    public function test_payable_owner_is_not_inferred_from_the_session(): void
    {
        $obligation = $this->obligation();
        $someoneElse = User::factory()->create(['status' => 'active']);

        $this->actingAs($someoneElse);

        $this->assertSame((int) $obligation->user_id, $obligation->paymentUserId());
        $this->assertNotSame($someoneElse->id, $obligation->paymentUserId());
    }

    /** The obligation reference is what a student sees and an operator searches by. */
    public function test_payable_reference_is_the_booking_reference(): void
    {
        $obligation = $this->obligation();

        $this->assertSame($obligation->booking->reference, $obligation->paymentReference());
    }

    /**
     * Obligation reference != attempt identity. The reference must stay
     * put across retries, which is precisely why it is not the legacy
     * `idempotency_key` — that belongs to a single attempt.
     */
    public function test_payable_reference_is_stable_across_attempts(): void
    {
        $obligation = $this->obligation();
        $before = $obligation->paymentReference();

        $first = $this->service()->startAttempt($obligation, 'razorpay', 'PAY-ATTEMPT-ONE');
        $this->service()->transition($first, PaymentStatus::Failed);
        $this->service()->startAttempt($obligation, 'razorpay', 'PAY-ATTEMPT-TWO');

        $this->assertSame($before, $obligation->fresh()->paymentReference());
        $this->assertNotSame($obligation->idempotency_key, $obligation->paymentReference());
    }

    public function test_payable_metadata_carries_support_context_only(): void
    {
        $obligation = $this->obligation();
        $metadata = $obligation->paymentMetadata();

        $this->assertSame($obligation->booking_id, $metadata['booking_id']);
        $this->assertSame($obligation->booking->reference, $metadata['booking_reference']);
        $this->assertSame((int) $obligation->user_id, $metadata['student_id']);
    }

    /** Never credentials, signatures, or card details on an attempt row. */
    public function test_payable_metadata_carries_no_secrets(): void
    {
        $obligation = $this->obligation();
        $obligation->forceFill(['provider_signature' => 'sig_should_never_travel'])->save();

        $encoded = json_encode($obligation->fresh()->paymentMetadata());

        foreach (['signature', 'secret', 'key_secret', 'card', 'cvv', 'token'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, (string) $encoded);
        }
    }

    // ── Morph identity (Parts 8, 9, 37) ───────────────────────────────────

    public function test_payable_type_is_a_stable_alias_not_a_fqcn(): void
    {
        $this->assertSame('booking_payment', $this->obligation()->paymentPayableType());
        $this->assertSame(BookingPayment::class, Relation::getMorphedModel('booking_payment'));
    }

    public function test_attempt_persists_the_alias_and_rehydrates_the_booking_payment(): void
    {
        $obligation = $this->obligation();

        $payment = $this->service()->startAttempt($obligation, 'razorpay');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'payable_type' => 'booking_payment',
            'payable_id' => (string) $obligation->getKey(),
        ]);

        $reloaded = Payment::query()->findOrFail($payment->id);

        $this->assertInstanceOf(BookingPayment::class, $reloaded->payable);
        $this->assertTrue($obligation->is($reloaded->payable));

        // No FQCN may ever reach the column.
        $this->assertStringNotContainsString('\\', (string) $reloaded->payable_type);
    }

    /**
     * Adding an alias is append-only. Existing package rows must
     * hydrate exactly as before — a changed alias orphans history.
     */
    public function test_existing_package_morph_alias_is_unchanged(): void
    {
        $this->assertSame('package_purchase', StudentPackagePurchase::PAYABLE_TYPE);
        $this->assertSame(StudentPackagePurchase::class, Relation::getMorphedModel('package_purchase'));
        $this->assertNotSame(StudentPackagePurchase::PAYABLE_TYPE, BookingPayment::PAYABLE_TYPE);
    }

    /**
     * The alias governs the payments ledger ONLY.
     *
     * Eloquent derives getMorphClass() from the same morph map
     * globally, so registering the alias would otherwise rewrite this
     * model's polymorphic identity everywhere at once — including
     * `activity_log.subject_type`, where every historical row was
     * written under the class name. That would split the audit trail of
     * a financial model into "before PAY-4A" and "after", which no
     * amount of alias tidiness is worth.
     */
    public function test_morph_identity_is_the_canonical_alias_everywhere(): void
    {
        $this->assertSame(BookingPayment::PAYABLE_TYPE, (new BookingPayment)->getMorphClass());
        $this->assertStringNotContainsString('\\', (new BookingPayment)->getMorphClass());
    }

    public function test_activity_log_records_booking_payments_under_the_canonical_alias(): void
    {
        $obligation = $this->obligation();

        $obligation->update(['status' => BookingPaymentRecordStatus::Captured]);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => BookingPayment::PAYABLE_TYPE,
            'subject_id' => $obligation->getKey(),
        ]);

        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => BookingPayment::class,
        ]);
    }

    /** The attempt relations resolve through the same alias the ledger stores. */
    public function test_attempt_relations_resolve_through_the_alias(): void
    {
        $obligation = $this->obligation();
        $payment = $this->service()->startAttempt($obligation, 'razorpay', 'PAY-REL');

        $this->assertTrue($obligation->paymentAttempts()->whereKey($payment->id)->exists());
        $this->assertNull($obligation->settledPayment);

        $this->service()->transition($payment, PaymentStatus::Paid);

        $this->assertSame($payment->id, $obligation->fresh()->settledPayment?->id);
    }

    // ── Attempt ledger (Parts 18, 19, 20) ─────────────────────────────────

    /**
     * The whole point of the target model: attempt #1 failing leaves a
     * durable record, and attempt #2 is a SEPARATE row. Today's
     * `booking_payments` overwrites in place and loses that history.
     */
    public function test_failed_attempt_history_survives_a_retry(): void
    {
        $obligation = $this->obligation();

        $first = $this->service()->startAttempt($obligation, 'razorpay', 'PAY-FIRST');
        $this->assertSame('booking_payment', $first->payable_type);
        $this->assertSame(499900, (int) $first->amount_minor);
        $this->assertSame('INR', $first->currency_code);
        $this->assertSame((int) $obligation->user_id, (int) $first->user_id);

        $this->service()->transition($first, PaymentStatus::Failed, ['failure_code' => 'card_declined']);

        $second = $this->service()->startAttempt($obligation, 'razorpay', 'PAY-SECOND');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(PaymentStatus::Failed, $first->fresh()->status);
        $this->assertSame('card_declined', $first->fresh()->failure_code);
        $this->assertSame(PaymentStatus::Pending, $second->fresh()->status);

        $this->assertSame(2, Payment::query()
            ->forPayable('booking_payment', (string) $obligation->getKey())
            ->count());
    }

    /**
     * Booking inherits the generic invariant simply by becoming a
     * Payable — no Booking-specific constraint was added. This exercises
     * the real DB unique index, not a mocked repository.
     */
    public function test_only_one_open_attempt_is_permitted(): void
    {
        $obligation = $this->obligation();

        $open = $this->service()->startAttempt($obligation, 'razorpay', 'PAY-OPEN');

        try {
            $this->service()->startAttempt($obligation, 'razorpay', 'PAY-SECOND-OPEN');
            $this->fail('A second open attempt was allowed for one booking obligation.');
        } catch (PaymentAttemptAlreadyOpenException $e) {
            $this->assertSame($open->id, $e->attempt->id);
        }

        $this->assertSame(1, Payment::query()
            ->forPayable('booking_payment', (string) $obligation->getKey())
            ->count());
    }

    /**
     * The service check above runs inside a transaction and fires
     * first, so on its own it would not prove the DATABASE protects a
     * booking obligation — and under real concurrency the service check
     * is exactly the one that loses. This bypasses the service and
     * writes straight to the table: the unique index on
     * (payable_type, payable_id, open_attempt_marker) must reject the
     * second open row itself, with no application code involved.
     */
    public function test_the_database_index_itself_rejects_a_second_open_booking_attempt(): void
    {
        $obligation = $this->obligation();
        $open = $this->service()->startAttempt($obligation, 'razorpay', 'PAY-DB-FIRST');

        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            'id' => (string) Str::uuid(),
            'payable_type' => 'booking_payment',
            'payable_id' => (string) $obligation->getKey(),
            'user_id' => $obligation->user_id,
            'provider' => 'razorpay',
            'amount_minor' => $obligation->amount_minor,
            'currency_code' => $obligation->currency_code,
            'status' => PaymentStatus::Pending->value,
            'idempotency_key' => 'PAY-DB-SECOND',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotNull($open);
    }

    /** Two obligations are independent — the constraint is per payable, not global. */
    public function test_two_booking_obligations_may_each_hold_an_open_attempt(): void
    {
        $this->service()->startAttempt($this->obligation(), 'razorpay', 'PAY-A');
        $this->service()->startAttempt($this->obligation(), 'razorpay', 'PAY-B');

        $this->assertSame(2, Payment::query()->where('payable_type', 'booking_payment')->open()->count());
    }

    /**
     * Race B: two workers holding the same attempt, both seeing a null
     * order id, both about to call the gateway. Exactly one may win the
     * claim, or the student gets two live provider orders.
     */
    public function test_initialization_claim_admits_exactly_one_worker(): void
    {
        $payment = $this->service()->startAttempt($this->obligation(), 'razorpay', 'PAY-CLAIM');

        $this->assertTrue($this->service()->claimInitialization($payment));
        $this->assertFalse($this->service()->claimInitialization($payment->fresh()));
        $this->assertNotNull($payment->fresh()->initialization_claimed_at);
    }

    // ── Non-payable bookings (Parts 23, 24, 25) ───────────────────────────

    /**
     * The hard invariant. Making BookingPayment payable must NOT make
     * every booking payable: a package-funded lesson was paid for when
     * the package was bought, and must never open a checkout, create an
     * obligation row, or debit a wallet at booking time.
     */
    public function test_package_funded_booking_creates_no_obligation_and_no_attempt(): void
    {
        $booking = Booking::factory()->create([
            'payment_status' => BookingPaymentStatus::PackageFunded,
        ]);

        $this->assertSame(0, BookingPayment::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(0, Payment::query()->where('payable_type', 'booking_payment')->count());
        $this->assertDatabaseMissing('wallet_ledger_entries', ['source_id' => $booking->id]);
    }

    public function test_free_and_not_required_bookings_create_no_attempt(): void
    {
        foreach ([BookingPaymentStatus::NotRequired, BookingPaymentStatus::PackageFunded] as $status) {
            Booking::factory()->create(['payment_status' => $status]);
        }

        $this->assertSame(0, Payment::query()->where('payable_type', 'booking_payment')->count());
    }

    /**
     * Wallet is an internal funding source, not an external provider
     * attempt. PAY-4A deliberately does not migrate it into the
     * provider attempt ledger merely for symmetry.
     */
    public function test_wallet_funded_obligations_are_not_migrated_to_the_attempt_ledger(): void
    {
        $obligation = $this->obligation();
        $obligation->update(['provider' => 'wallet', 'provider_order_id' => null]);

        $this->assertSame(0, Payment::query()
            ->forPayable('booking_payment', (string) $obligation->getKey())
            ->count());
    }

    // ── Reporting safety (Parts 27, 28) ───────────────────────────────────

    /**
     * PAY-AUD-008's migration constraint, pinned before it can bite.
     *
     * Booking revenue is recognised from the OBLIGATION
     * (`booking_payments`), never from attempts. Once a booking can have
     * many attempt rows, summing `payments.amount_minor` would count a
     * single sale once per retry. Nothing in reporting may read the
     * attempt ledger for booking revenue.
     */
    public function test_booking_attempts_are_never_a_booking_revenue_source(): void
    {
        $obligation = $this->obligation();

        $first = $this->service()->startAttempt($obligation, 'razorpay', 'PAY-R1');
        $this->service()->transition($first, PaymentStatus::Failed);
        $this->service()->startAttempt($obligation, 'razorpay', 'PAY-R2');

        // Three attempt-shaped rows exist across two ledgers for ONE sale.
        $this->assertSame(2, Payment::query()->where('payable_type', 'booking_payment')->count());

        $capturedObligations = DB::table('booking_payments')
            ->where('status', BookingPaymentRecordStatus::Captured->value)
            ->sum('amount_minor');

        $this->assertSame(0, (int) $capturedObligations, 'Unsettled obligation must contribute no revenue.');

        // And the reporting repository reads the obligation table only.
        $source = file_get_contents(app_path('Reporting/Repositories/PaymentFinancialReportRepository.php'));
        $this->assertStringContainsString("DB::table('booking_payments')", (string) $source);
        $this->assertStringNotContainsString("DB::table('payments')", (string) $source);
    }

    // ── Live path untouched (Part 17) ─────────────────────────────────────

    /**
     * PAY-4A is foundation only. Creating a generic attempt must not
     * touch the obligation row's own legacy fields, its status, or the
     * booking's payment status — live checkout still owns all of those
     * until PAY-4B.
     */
    public function test_generic_attempt_does_not_disturb_the_legacy_booking_payment_row(): void
    {
        $obligation = $this->obligation();
        $before = $obligation->only([
            'provider', 'provider_order_id', 'provider_payment_id',
            'idempotency_key', 'status', 'paid_at', 'failed_at',
        ]);
        $bookingStatusBefore = $obligation->booking->payment_status;

        $this->service()->startAttempt($obligation, 'razorpay', 'PAY-NO-SIDE-EFFECT');

        $after = $obligation->fresh()->only(array_keys($before));

        $this->assertEquals($before, $after);
        $this->assertSame($bookingStatusBefore, $obligation->booking->fresh()->payment_status);
    }

    /** Settlement is never a side effect of opening an attempt — that needs verified provider evidence. */
    public function test_opening_an_attempt_settles_nothing(): void
    {
        $obligation = $this->obligation();

        $payment = $this->service()->startAttempt($obligation, 'razorpay', 'PAY-NOT-SETTLED');

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertNull($payment->paid_at);
        $this->assertNotSame(BookingPaymentRecordStatus::Captured, $obligation->fresh()->status);
        $this->assertSame(0, DB::table('invoices')->count());
    }
}
