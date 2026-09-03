<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingException;
use App\Enums\StudentStatus;
use App\Exceptions\Student\StudentActionNotAvailableException;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingPayment;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Notifications\Booking\BookingPaymentSucceededNotification;
use App\Settings\FeatureSettings;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS §13.13 — a student paying for their own pending lesson booking
 * directly from wallet balance, as a
 * synchronous alternative to gateway checkout. Reuses
 * WalletLedgerService::debit() and the same successful-payment
 * finalization (BookingPaymentService::finalizeSuccessfulPayment())
 * that markPaid() uses — these tests focus on what's specific to the
 * wallet path: authorization, balance/currency/feature preconditions,
 * atomicity, and traceability.
 */
class WalletLessonPaymentTest extends TestCase
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

        app(FeatureSettings::class)->wallet_enabled = true;

        $this->paidType = BookingType::factory()->create(['key' => 'paid_one_to_one', 'is_paid' => true]);
    }

    /** @return array{0: Booking, 1: User} */
    private function pendingBooking(int $priceMinor = 49900, string $currency = 'INR', ?BookingType $type = null): array
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        // A real student always has a billing country, and the gateway
        // checkout path refuses to quote one without an active country
        // ("Payments are not available in your country yet"). The wallet
        // path never consulted it, which is why this was missing.
        $country = Country::factory()->create(['status' => 'active']);
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $country->id]);

        $type ??= $this->paidType;
        $startsAt = CarbonImmutable::now('UTC')->addDays(3);

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'booking_type_id' => $type->id,
            'status' => BookingStatus::Pending,
            'payment_status' => BookingPaymentStatus::Pending,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'price' => $priceMinor / 100,
            'currency' => $currency,
            'reserved_until' => CarbonImmutable::now('UTC')->addMinutes(15),
        ]);

        return [$booking, $student];
    }

    private function fundWallet(User $student, int $amountMinor, string $currency = 'INR'): Wallet
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($student, $currency, $student);
        app(WalletLedgerService::class)->credit($wallet, $amountMinor, WalletLedgerEntryType::PromotionalCredit, $student);

        return $wallet->fresh();
    }

    // ── 1. Happy path ────────────────────────────────────────────────

    public function test_eligible_student_pays_pending_booking_from_sufficient_wallet_balance(): void
    {
        [$booking, $student] = $this->pendingBooking();
        $this->fundWallet($student, 100000);

        $paid = app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);

        $this->assertSame(BookingPaymentStatus::Paid, $paid->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $paid->status);
    }

    // ── 2 & 3. Integer minor-unit debit, exactly one ledger entry ──────

    public function test_wallet_debit_is_exactly_the_bookings_price_in_integer_minor_units(): void
    {
        [$booking, $student] = $this->pendingBooking(priceMinor: 49900);
        $wallet = $this->fundWallet($student, 100000);

        app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);

        $this->assertSame(50100, $wallet->fresh()->balance_minor);
        $this->assertSame(
            1,
            WalletLedgerEntry::query()->where('wallet_id', $wallet->id)->where('entry_type', WalletLedgerEntryType::BookingPayment->value)->count(),
        );
    }

    // ── 4. Source traceability back to the BookingPayment row ──────────

    public function test_wallet_debit_entry_links_back_to_the_booking_payment_row(): void
    {
        [$booking, $student] = $this->pendingBooking();
        $wallet = $this->fundWallet($student, 100000);

        app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->sole();
        $entry = WalletLedgerEntry::query()->where('wallet_id', $wallet->id)->where('entry_type', WalletLedgerEntryType::BookingPayment->value)->sole();

        $this->assertSame(BookingPayment::class, $entry->source_type);
        $this->assertSame((string) $payment->id, $entry->source_id);
    }

    // ── 5. BookingPayment row recorded as a successful wallet payment ──

    public function test_booking_payment_row_is_recorded_as_wallet_provider_captured(): void
    {
        [$booking, $student] = $this->pendingBooking();
        $this->fundWallet($student, 100000);

        app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame('wallet', $payment->provider);
        $this->assertSame(BookingPaymentRecordStatus::Captured, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertNull($payment->provider_order_id);
        $this->assertNull($payment->provider_payment_id);
    }

    // ── 6. Existing success finalization pipeline runs unmodified ──────

    public function test_existing_success_finalization_pipeline_runs_for_wallet_payment(): void
    {
        Notification::fake();
        [$booking, $student] = $this->pendingBooking();
        $this->fundWallet($student, 100000);

        $paid = app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);

        $this->assertNull($paid->fresh()->reserved_until, 'The reservation must clear via the same finalizeSuccessfulPayment() path markPaid() uses.');

        $activity = BookingActivity::query()
            ->where('booking_id', $booking->id)
            ->where('action', BookingActivityAction::PaymentStatusChanged)
            ->exists();
        $this->assertTrue($activity, 'The booking activity timeline must record the payment exactly as it does for a gateway payment.');

        Notification::assertSentTo($student, BookingPaymentSucceededNotification::class);
    }

    // ── 7. Insufficient balance rejects cleanly ─────────────────────────

    public function test_insufficient_wallet_balance_is_rejected_and_leaves_state_unchanged(): void
    {
        [$booking, $student] = $this->pendingBooking(priceMinor: 49900);
        $this->fundWallet($student, 10000);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('not sufficient');

        try {
            app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);
        } finally {
            $this->assertSame(BookingPaymentStatus::Pending, $booking->fresh()->payment_status);
            $this->assertSame(0, BookingPayment::query()->where('booking_id', $booking->id)->count());
            $this->assertSame(10000, Wallet::query()->where('user_id', $student->id)->first()->balance_minor);
        }
    }

    // ── 8. Wallet currency mismatch rejects ─────────────────────────────

    public function test_wallet_currency_mismatch_with_the_booking_is_rejected(): void
    {
        Currency::query()->firstOrCreate(['code' => 'GBP'], [
            'name' => 'British Pound', 'symbol' => '£', 'numeric_code' => '826',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 2,
        ]);

        [$booking, $student] = $this->pendingBooking(priceMinor: 3000, currency: 'GBP');
        $this->fundWallet($student, 100000, 'INR');

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('currency');

        app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);
    }

    // ── 9. Inactive currency rejects ────────────────────────────────────

    public function test_inactive_booking_currency_is_rejected(): void
    {
        [$booking, $student] = $this->pendingBooking();
        $this->fundWallet($student, 100000);

        Currency::query()->where('code', 'INR')->update(['status' => 'inactive']);

        $this->expectException(BookingException::class);

        app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);
    }

    // ── 10. Feature-disabled rejects at the service level ───────────────

    public function test_wallet_payments_disabled_rejects_at_the_service_level(): void
    {
        app(FeatureSettings::class)->wallet_enabled = false;

        [$booking, $student] = $this->pendingBooking();
        $this->fundWallet($student, 100000);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('not currently enabled');

        try {
            app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);
        } finally {
            $this->assertSame(BookingPaymentStatus::Pending, $booking->fresh()->payment_status);
        }
    }

    // ── 11. Paying for another student's booking is rejected ────────────

    public function test_paying_for_another_students_booking_is_rejected(): void
    {
        [$booking] = $this->pendingBooking();
        $otherStudent = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->fundWallet($otherStudent, 100000);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('your own booking');

        app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $otherStudent);
    }

    // ── 12. A restricted student lifecycle is rejected ──────────────────

    public function test_a_suspended_students_wallet_payment_is_rejected(): void
    {
        [$booking, $student] = $this->pendingBooking();
        $this->fundWallet($student, 100000);

        UserProfile::query()->where('user_id', $student->id)->update(['student_status' => StudentStatus::Suspended]);

        $this->expectException(StudentActionNotAvailableException::class);

        try {
            app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);
        } finally {
            $this->assertSame(BookingPaymentStatus::Pending, $booking->fresh()->payment_status);
            $this->assertSame(0, BookingPayment::query()->where('booking_id', $booking->id)->count());
        }
    }

    // ── 13. Non-payable booking states reject ────────────────────────────

    public function test_a_cancelled_booking_rejects_wallet_payment(): void
    {
        [$booking, $student] = $this->pendingBooking();
        $this->fundWallet($student, 100000);
        $booking->forceFill(['status' => BookingStatus::Cancelled])->save();

        $this->expectException(BookingException::class);

        app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);
    }

    public function test_an_already_paid_booking_rejects_a_second_wallet_payment(): void
    {
        [$booking, $student] = $this->pendingBooking();
        $this->fundWallet($student, 100000);
        $booking->forceFill(['payment_status' => BookingPaymentStatus::Paid])->save();

        $this->expectException(BookingException::class);

        app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);
    }

    // ── 14. A repeated identical request produces exactly one debit ────

    public function test_a_repeated_identical_wallet_payment_request_produces_exactly_one_debit(): void
    {
        [$booking, $student] = $this->pendingBooking();
        $wallet = $this->fundWallet($student, 100000);

        app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);

        $secondAttemptRejected = false;
        try {
            app(BookingPaymentServiceInterface::class)->payWithWallet($booking->fresh(), $student);
        } catch (BookingException) {
            $secondAttemptRejected = true;
        }

        $this->assertTrue($secondAttemptRejected, 'The already-Paid precondition must reject the repeated request, not double-debit.');
        $this->assertSame(50100, $wallet->fresh()->balance_minor);
        $this->assertSame(
            1,
            WalletLedgerEntry::query()->where('wallet_id', $wallet->id)->where('entry_type', WalletLedgerEntryType::BookingPayment->value)->count(),
        );
    }

    // ── 15. A gateway-paid booking cannot also be wallet-paid ───────────

    public function test_a_gateway_paid_booking_cannot_also_be_paid_by_wallet(): void
    {
        [$booking, $student] = $this->pendingBooking();
        $wallet = $this->fundWallet($student, 100000);

        BookingPayment::query()->create([
            'booking_id' => $booking->id,
            'user_id' => $student->id,
            'provider' => 'fake',
            'amount_minor' => 49900,
            'currency_code' => 'INR',
            'status' => BookingPaymentRecordStatus::Captured,
            'idempotency_key' => 'PAY-GATEWAY-1',
        ]);
        $booking->forceFill(['payment_status' => BookingPaymentStatus::Paid, 'status' => BookingStatus::Confirmed])->save();

        $this->expectException(BookingException::class);

        try {
            app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);
        } finally {
            $this->assertSame(100000, $wallet->fresh()->balance_minor, 'A booking already settled by the gateway must never also debit the wallet.');
        }
    }

    // ── 16. A failure after the debit rolls back everything ─────────────

    public function test_a_failure_after_the_wallet_debit_rolls_back_the_debit_and_leaves_all_state_unchanged(): void
    {
        [$booking, $student] = $this->pendingBooking();
        $wallet = $this->fundWallet($student, 100000);

        $mockRepository = Mockery::mock(BookingRepositoryInterface::class);
        $mockRepository->shouldReceive('updatePaymentStatus')->andThrow(new RuntimeException('Simulated finalization failure.'));
        $this->app->instance(BookingRepositoryInterface::class, $mockRepository);

        $threw = false;
        try {
            app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);
        } catch (RuntimeException) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertSame(100000, $wallet->fresh()->balance_minor, 'The wallet debit must roll back with the rest of the transaction.');
        // 1, not 0: the funding credit from fundWallet() itself is a real,
        // already-committed entry — only the (rolled-back) debit entry
        // must be absent.
        $this->assertSame(1, WalletLedgerEntry::query()->where('wallet_id', $wallet->id)->count());
        $this->assertSame(0, BookingPayment::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(BookingPaymentStatus::Pending, $booking->fresh()->payment_status);
    }

    // ── 17. The existing gateway checkout method remains unchanged ──────

    public function test_gateway_checkout_initiate_remains_selectable_and_unchanged_for_a_booking_without_wallet_funds(): void
    {
        [$booking, $student] = $this->pendingBooking();

        $intent = app(BookingPaymentServiceInterface::class)->initiate($booking);

        $this->assertNotNull($intent);
        $this->assertSame(BookingPaymentStatus::Pending, $booking->fresh()->payment_status);
    }
}
