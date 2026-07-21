<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\CancellationRefundDecision;
use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingException;
use App\Booking\Services\CancellationRefundPolicy;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingPayment;
use App\Models\BookingType;
use App\Models\Currency;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24C — integration tests exercising the full runtime path:
 * BookingService::cancel() → frozen CancellationRefundDecision →
 * BookingCancelled(after-commit) → SyncPaymentOnCancellation →
 * BookingPaymentService::refundToWallet()/recordIneligibleCancellation().
 * QUEUE_CONNECTION=sync in testing, so the queued listener runs
 * synchronously within cancel() itself — no manual queue processing
 * needed.
 */
class CancellationRefundExecutionTest extends TestCase
{
    use RefreshDatabase;

    private BookingType $paidType;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->paidType = BookingType::factory()->create(['key' => 'paid_one_to_one', 'is_paid' => true]);
    }

    private function setWindow(int $hours): void
    {
        $settings = app(BookingSettings::class);
        $settings->cancellation_window_hours = $hours;
        $settings->save();
    }

    /** @return array{0: Booking, 1: BookingPayment, 2: User} */
    private function paidBooking(CarbonImmutable $startsAt, int $amountMinor = 49900, string $currency = 'INR', ?BookingType $type = null): array
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $type ??= $this->paidType;

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'booking_type_id' => $type->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Paid,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'price' => $amountMinor / 100,
            'currency' => $currency,
            'payment_reference' => 'PAY-'.strtoupper(Str::random(10)),
        ]);

        $payment = BookingPayment::query()->create([
            'booking_id' => $booking->id,
            'user_id' => $student->id,
            'provider' => 'fake',
            'amount_minor' => $amountMinor,
            'currency_code' => $currency,
            'status' => 'captured',
            'idempotency_key' => $booking->payment_reference,
        ]);

        return [$booking, $payment, $student];
    }

    private function cancel(Booking $booking, BookingActor $actor, CarbonImmutable $at, ?string $reason = null): Booking
    {
        CarbonImmutable::setTestNow($at);

        try {
            return app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData($actor, $reason));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    // ── 1. Eligible cancellation → one full wallet refund ───────────────────

    public function test_student_cancels_before_cutoff_and_receives_one_full_wallet_refund(): void
    {
        $this->setWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        [$booking, , $student] = $this->paidBooking($startsAt);

        $cancelled = $this->cancel($booking, BookingActor::Student, $startsAt->subHours(48));

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
        $this->assertSame(BookingPaymentStatus::Refunded, $cancelled->fresh()->payment_status);

        $wallet = Wallet::query()->where('user_id', $student->id)->where('currency_code', 'INR')->first();
        $this->assertNotNull($wallet);
        $this->assertSame(49900, $wallet->balance_minor);
        $this->assertSame(1, WalletLedgerEntry::query()->where('wallet_id', $wallet->id)->count());
    }

    // ── 3. Ineligible (inside window) → no refund ───────────────────────────

    public function test_student_cancels_inside_the_window_and_receives_no_refund(): void
    {
        $this->setWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        [$booking, $payment, $student] = $this->paidBooking($startsAt);

        $cancelled = $this->cancel($booking, BookingActor::Student, $startsAt->subHours(2));

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
        $this->assertSame(BookingPaymentStatus::Paid, $cancelled->fresh()->payment_status);

        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
        $this->assertNull(Wallet::query()->where('user_id', $student->id)->first());

        $this->assertSame('not_eligible_late_cancellation', $payment->fresh()->metadata['refund_resolution']);
    }

    // ── 4. Cancel at/after start → no refund ────────────────────────────────

    public function test_student_cancels_after_lesson_start_and_receives_no_refund(): void
    {
        $this->setWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        [$booking, , $student] = $this->paidBooking($startsAt);

        $this->cancel($booking, BookingActor::Student, $startsAt->addMinutes(5));

        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }

    // ── 6. Free demo cancellation creates no wallet entry ───────────────────

    public function test_free_demo_cancellation_creates_no_wallet_entry(): void
    {
        $demoType = BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false]);
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'booking_type_id' => $demoType->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
        ]);

        $this->cancel($booking, BookingActor::Student, $startsAt->addMinutes(1));

        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
        $this->assertSame(0, BookingPayment::query()->where('booking_id', $booking->id)->count());
    }

    // ── 7. Unpaid/failed paid booking creates no refund ─────────────────────

    public function test_unpaid_booking_cancellation_creates_no_refund(): void
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'booking_type_id' => $this->paidType->id,
            'status' => BookingStatus::Pending,
            'payment_status' => BookingPaymentStatus::Failed,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
        ]);

        $this->cancel($booking, BookingActor::Student, $startsAt->subHours(48));

        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }

    // ── 8 & 9. Responsibility-based cancellations always refund in full ─────

    public function test_instructor_caused_cancellation_always_refunds_in_full(): void
    {
        $this->setWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        [$booking, , $student] = $this->paidBooking($startsAt);

        $this->cancel($booking, BookingActor::Instructor, $startsAt->addMinutes(5));

        $wallet = Wallet::query()->where('user_id', $student->id)->first();
        $this->assertNotNull($wallet);
        $this->assertSame(49900, $wallet->balance_minor);
    }

    public function test_admin_cancellation_always_refunds_in_full(): void
    {
        $this->setWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        [$booking, , $student] = $this->paidBooking($startsAt);

        $this->cancel($booking, BookingActor::Admin, $startsAt->addHour());

        $wallet = Wallet::query()->where('user_id', $student->id)->first();
        $this->assertNotNull($wallet);
        $this->assertSame(49900, $wallet->balance_minor);
    }

    // ── 11. Refund amount comes from the captured payment, not current price ─

    public function test_refund_amount_comes_from_the_captured_payment_not_the_current_booking_type_price(): void
    {
        $this->setWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        [$booking, , $student] = $this->paidBooking($startsAt, amountMinor: 49900);

        // The booking-type's own price snapshot changes after payment —
        // must never influence the refund amount, which comes solely
        // from the captured BookingPayment row.
        $booking->forceFill(['price' => '999.00'])->save();

        $this->cancel($booking, BookingActor::Student, $startsAt->subHours(48));

        $wallet = Wallet::query()->where('user_id', $student->id)->first();
        $this->assertSame(49900, $wallet->balance_minor);
    }

    // ── 12. Refund currency matches the original transaction ───────────────

    public function test_refund_currency_matches_the_original_captured_payment_currency(): void
    {
        Currency::query()->firstOrCreate(['code' => 'GBP'], [
            'name' => 'British Pound', 'symbol' => '£', 'numeric_code' => '826',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 2,
        ]);

        $this->setWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        [$booking, , $student] = $this->paidBooking($startsAt, amountMinor: 3000, currency: 'GBP');

        $this->cancel($booking, BookingActor::Student, $startsAt->subHours(48));

        $wallet = Wallet::query()->where('user_id', $student->id)->first();
        $this->assertSame('GBP', $wallet->currency_code);
        $this->assertSame(3000, $wallet->balance_minor);
    }

    // ── 13. Duplicate event/job delivery produces only one wallet credit ────

    public function test_duplicate_listener_delivery_produces_only_one_wallet_credit(): void
    {
        $this->setWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        [$booking, , $student] = $this->paidBooking($startsAt);

        $this->cancel($booking, BookingActor::Student, $startsAt->subHours(48));

        // Simulate a second, duplicate delivery of the already-processed
        // cancellation event by re-invoking the wallet service directly.
        // The first delivery already moved payment_status to Refunded,
        // so the resolved-payment guard rejects the repeat before any
        // second wallet credit can occur.
        $exceptionSeen = false;
        try {
            app(BookingPaymentServiceInterface::class)->refundToWallet($booking->fresh(), 'Booking cancelled');
        } catch (BookingException) {
            $exceptionSeen = true;
        }

        $this->assertTrue($exceptionSeen, 'A duplicate refund-execution attempt must be rejected, not double-processed.');

        $wallet = Wallet::query()->where('user_id', $student->id)->first();
        $this->assertSame(49900, $wallet->balance_minor);
        $this->assertSame(1, WalletLedgerEntry::query()->where('wallet_id', $wallet->id)->count());
    }

    public function test_duplicate_ineligible_cancellation_disposition_is_recorded_once(): void
    {
        $this->setWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        [$booking, $payment] = $this->paidBooking($startsAt);

        $decision = app(CancellationRefundPolicy::class)->decide($booking, BookingActor::Student, $startsAt->subHours(1));

        $paymentService = app(BookingPaymentServiceInterface::class);
        $paymentService->recordIneligibleCancellation($booking, $decision);

        // A second delivery must not throw and must not alter anything.
        $exceptionSeen = false;
        try {
            $paymentService->recordIneligibleCancellation($booking->fresh(), $decision);
        } catch (BookingException) {
            $exceptionSeen = true;
        }

        $this->assertTrue($exceptionSeen, 'A duplicate disposition attempt must be rejected, not silently reapplied.');
        $this->assertSame('not_eligible_late_cancellation', $payment->fresh()->metadata['refund_resolution']);
    }

    // ── 15. Changing the setting after the decision does not change the outcome ─

    public function test_changing_the_setting_after_the_decision_was_frozen_does_not_alter_the_outcome(): void
    {
        $this->setWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        [$booking, $payment, $student] = $this->paidBooking($startsAt);

        // Frozen while window=24h: 1 hour before start is late.
        $decision = app(CancellationRefundPolicy::class)
            ->decide($booking, BookingActor::Student, $startsAt->subHour());
        $this->assertFalse($decision->eligible);

        // Admin relaxes the window to 0h — would make this eligible if
        // recomputed fresh, but the frozen $decision object must not care.
        $this->setWindow(0);

        app(BookingPaymentServiceInterface::class)->recordIneligibleCancellation($booking, $decision);

        $this->assertSame('not_eligible_late_cancellation', $payment->fresh()->metadata['refund_resolution']);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }

    // ── 16. A prior reschedule uses the final scheduled start ───────────────

    public function test_a_prior_reschedule_uses_the_final_scheduled_start_at_cancellation(): void
    {
        $this->setWindow(24);
        $originalStart = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $newStart = CarbonImmutable::parse('2026-08-20 10:00:00', 'UTC');
        [$booking, , $student] = $this->paidBooking($originalStart);

        // Cancelling now (relative to the ORIGINAL start) would be late;
        // relative to the rescheduled start, it is comfortably early. The
        // reschedule-limit/availability pipeline itself is out of scope
        // for this phase, so the "already rescheduled" state is applied
        // directly rather than by driving the full reschedule() action.
        $now = $originalStart->subHours(2);
        $booking->forceFill(['starts_at' => $newStart, 'ends_at' => $newStart->addMinutes(30)])->save();

        $this->cancel($booking->fresh(), BookingActor::Student, $now);

        $wallet = Wallet::query()->where('user_id', $student->id)->first();
        $this->assertNotNull($wallet, 'Refund should be eligible against the rescheduled start, not the original one.');
        $this->assertSame(49900, $wallet->balance_minor);
    }

    // ── 17. Individual recurring-occurrence cancellation is isolated ───────

    public function test_cancelling_one_recurring_occurrence_does_not_affect_the_sibling_occurrence(): void
    {
        $this->setWindow(24);
        $groupId = (string) Str::uuid();
        $start1 = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $start2 = CarbonImmutable::parse('2026-08-17 10:00:00', 'UTC');

        [$occurrence1, , $student] = $this->paidBooking($start1);
        $occurrence1->forceFill(['meta' => ['recurring_group' => $groupId]])->save();

        $student2Occurrence = Booking::factory()->create([
            'student_id' => $student->id,
            'booking_type_id' => $this->paidType->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Paid,
            'starts_at' => $start2,
            'ends_at' => $start2->addMinutes(30),
            'price' => '499.00',
            'currency' => 'INR',
            'payment_reference' => 'PAY-'.strtoupper(Str::random(10)),
            'meta' => ['recurring_group' => $groupId],
        ]);
        BookingPayment::query()->create([
            'booking_id' => $student2Occurrence->id,
            'user_id' => $student->id,
            'provider' => 'fake',
            'amount_minor' => 49900,
            'currency_code' => 'INR',
            'status' => 'captured',
            'idempotency_key' => $student2Occurrence->payment_reference,
        ]);

        // Cancel only the FIRST occurrence, late (inside the window) —
        // should not be refunded, and must not touch the second at all.
        $this->cancel($occurrence1, BookingActor::Student, $start1->subHour());

        $this->assertSame(BookingStatus::Cancelled, $occurrence1->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $student2Occurrence->fresh()->status);
        $this->assertSame(BookingPaymentStatus::Paid, $student2Occurrence->fresh()->payment_status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }

    // ── 20. Existing payment/wallet invariants remain intact ───────────────

    public function test_wallet_balance_matches_ledger_after_refund(): void
    {
        $this->setWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        [$booking, , $student] = $this->paidBooking($startsAt);

        $this->cancel($booking, BookingActor::Student, $startsAt->subHours(48));

        $wallet = Wallet::query()->where('user_id', $student->id)->first();
        $entry = WalletLedgerEntry::query()->where('wallet_id', $wallet->id)->sole();

        $this->assertSame($wallet->balance_minor, $entry->balance_after_minor);
        $this->assertSame(sprintf('cancellation-refund:%s', $booking->payments()->sole()->id), $entry->idempotency_key);
    }

    // ── 21. The cancellation decision is audit-traceable ────────────────────

    public function test_the_cancellation_decision_is_recorded_in_the_booking_activity_timeline(): void
    {
        $this->setWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        [$booking] = $this->paidBooking($startsAt);

        $this->cancel($booking, BookingActor::Student, $startsAt->subHours(2));

        $activity = BookingActivity::query()
            ->where('booking_id', $booking->id)
            ->where('action', BookingActivityAction::Cancelled)
            ->sole();

        $this->assertFalse($activity->meta['refund_eligible']);
        $this->assertSame('late_cancellation', $activity->meta['refund_policy_code']);

        $auditEntry = Activity::query()
            ->where('log_name', 'payments')
            ->where('description', 'like', '%cancelled outside the refund window%')
            ->exists();
        $this->assertTrue($auditEntry, 'Ineligible cancellation must also be traceable in the unified audit log.');
    }

    // ── Phase 25B (GAP-002) — a wallet-paid booking refunds through the ────
    // same unmodified pipeline as a gateway-paid booking: SyncPaymentOnCancellation
    // only ever looks for a Captured BookingPayment row regardless of provider.

    public function test_wallet_paid_booking_cancellation_credits_the_wallet_through_the_unmodified_refund_pipeline(): void
    {
        app(FeatureSettings::class)->wallet_enabled = true;

        $this->setWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'booking_type_id' => $this->paidType->id,
            'status' => BookingStatus::Pending,
            'payment_status' => BookingPaymentStatus::Pending,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'price' => '499.00',
            'currency' => 'INR',
        ]);

        $wallet = app(WalletService::class)->getOrCreateWallet($student, 'INR', $student);
        app(WalletLedgerService::class)->credit($wallet, 100000, WalletLedgerEntryType::PromotionalCredit, $student);

        $paid = app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);
        $this->assertSame(BookingPaymentStatus::Paid, $paid->payment_status);
        $this->assertSame(50100, $wallet->fresh()->balance_minor);

        $this->cancel($paid, BookingActor::Student, $startsAt->subHours(48));

        $walletAfterRefund = $wallet->fresh();
        $this->assertSame(100000, $walletAfterRefund->balance_minor, 'The refund must restore the wallet-paid amount, using the same refundToWallet() path as a gateway payment.');
        // 3 entries: the initial funding credit, the wallet-payment debit, and the cancellation-refund credit.
        $this->assertSame(3, WalletLedgerEntry::query()->where('wallet_id', $wallet->id)->count());

        $payment = BookingPayment::query()->where('booking_id', $paid->id)->sole();
        $this->assertSame('wallet', $payment->provider);
        $this->assertSame('wallet_credited', $payment->fresh()->metadata['refund_resolution']);
    }
}
