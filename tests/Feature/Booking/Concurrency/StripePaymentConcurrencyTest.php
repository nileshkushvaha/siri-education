<?php

declare(strict_types=1);

namespace Tests\Feature\Booking\Concurrency;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use App\Settings\BookingSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\Support\CreatesStudentLessonPrices;

/**
 * Real multi-process races for Phase 16C's Stripe collection additions
 * (concurrent initiation, webhook-vs-reconciliation, webhook-vs-expiry,
 * webhook-vs-cancellation, duplicate-webhook-vs-wallet-credit, and
 * timeout-vs-fallback). Reuses the tests/Concurrency/run-op.php harness
 * proven throughout the financial domain (Phase 15.1/14.4/16A/16A.1).
 * The 7th spec'd scenario ("wallet refund vs provider refund") is
 * already covered by BookingRefundConcurrencyTest.php — not duplicated
 * here, just re-run as part of the same 3-consecutive-run verification
 * pass.
 */
class StripePaymentConcurrencyTest extends ConcurrencyTestCase
{
    use CreatesStudentLessonPrices;

    private const SECRET_KEY = 'sk_test_concurrency123';

    private const PUBLISHABLE_KEY = 'pk_test_concurrency123';

    private const WEBHOOK_SECRET = 'whsec_test_concurrency123';

    // ── 1. Concurrent Stripe payment initiation ─────────────────────────

    public function test_concurrent_payment_initiation_creates_exactly_one_payment_row(): void
    {
        $booking = $this->reservePaidBooking();

        $results = $this->race([
            ['stripe-initiate', ['booking_id' => $booking->id]],
            ['stripe-initiate', ['booking_id' => $booking->id]],
        ]);

        // Whichever leg loses the race either safely reuses the winner's
        // row or is told to retry — neither ever produces a second row.
        $this->assertSame(1, BookingPayment::query()->where('booking_id', $booking->id)->count(), json_encode($results));
    }

    // ── 2. Webhook vs manual (on-demand) reconciliation ─────────────────

    public function test_webhook_and_manual_reconciliation_settle_the_same_payment_exactly_once(): void
    {
        [$booking, $payment] = $this->initiatedStripePayment();

        $this->race([
            ['stripe-webhook-succeed', $this->webhookArgs($booking, $payment)],
            ['reconcile-booking-payment', ['payment_id' => $payment->id]],
        ]);

        $payment->refresh();
        $booking->refresh();

        $this->assertSame(BookingPaymentRecordStatus::Captured, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
    }

    // ── 8. Reconciliation sweep vs webhook finalization ─────────────────

    public function test_reconciliation_sweep_and_webhook_settle_the_same_payment_exactly_once(): void
    {
        [$booking, $payment] = $this->initiatedStripePayment();

        $this->race([
            ['stripe-webhook-succeed', $this->webhookArgs($booking, $payment)],
            ['reconcile-booking-payments-sweep', []],
        ]);

        $payment->refresh();
        $booking->refresh();

        $this->assertSame(BookingPaymentRecordStatus::Captured, $payment->status);
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
    }

    // ── 3. Payment success vs reservation expiry ────────────────────────

    public function test_payment_success_racing_reservation_expiry_never_confirms_and_cancels_at_once(): void
    {
        [$booking, $payment] = $this->initiatedStripePayment();

        Booking::query()->whereKey($booking->id)->update(['reserved_until' => now()->subMinute()]);

        $this->race([
            ['stripe-webhook-succeed', $this->webhookArgs($booking, $payment)],
            ['release-expired-booking-reservations', []],
        ]);

        $booking->refresh();
        $payment->refresh();

        $this->assertSame(BookingPaymentRecordStatus::Captured, $payment->status);

        if ($booking->status === BookingStatus::Confirmed) {
            // Webhook won the race before expiry ran — normal settlement.
            $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        } else {
            // Expiry won — Option B: charge preserved, wallet-credited,
            // never a confirmed-and-cancelled booking at once.
            $this->assertSame(BookingStatus::Cancelled, $booking->status);
            $this->assertSame(BookingPaymentStatus::Refunded, $booking->payment_status);

            $wallet = Wallet::query()->where('user_id', $booking->attendee_id)->where('currency_code', $payment->currency_code)->first();
            $this->assertNotNull($wallet);
            $this->assertSame($payment->amount_minor, $wallet->balance_minor);
        }
    }

    // ── 4. Payment success vs manual cancellation ───────────────────────

    public function test_payment_success_racing_manual_cancellation_never_confirms_and_cancels_at_once(): void
    {
        [$booking, $payment] = $this->initiatedStripePayment();
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->race([
            ['stripe-webhook-succeed', $this->webhookArgs($booking, $payment)],
            ['cancel-booking-as-user', ['booking_id' => $booking->id, 'actor_id' => $actor->id]],
        ]);

        $booking->refresh();
        $payment->refresh();

        $this->assertSame(BookingPaymentRecordStatus::Captured, $payment->status);

        if ($booking->status === BookingStatus::Confirmed) {
            $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        } else {
            $this->assertSame(BookingStatus::Cancelled, $booking->status);
            // Either the normal wallet-first cancellation refund ran (if
            // cancellation won while payment_status was already Paid) or
            // Option B's late-terminal wallet credit ran (if cancellation
            // won first) — either way exactly one credit, never zero, never two.
            $wallet = Wallet::query()->where('user_id', $booking->attendee_id)->where('currency_code', $payment->currency_code)->first();
            $this->assertNotNull($wallet);
            $this->assertSame($payment->amount_minor, $wallet->balance_minor);
        }
    }

    // ── 5. Duplicate webhook vs wallet credit (late-success path) ───────

    public function test_duplicate_late_webhook_delivery_credits_wallet_exactly_once(): void
    {
        [$booking, $payment] = $this->initiatedStripePayment();

        // Booking already terminal (cancelled) before the payment settles
        // — the exact precondition BookingPaymentService::handleLateTerminalPayment()
        // (Option B) exists for.
        Booking::query()->whereKey($booking->id)->update([
            'status' => BookingStatus::Cancelled,
            'reserved_until' => null,
        ]);

        $args = $this->webhookArgs($booking, $payment);

        $this->race([
            ['stripe-webhook-succeed', $args],
            ['stripe-webhook-succeed', $args],
        ]);

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Refunded, $booking->payment_status);

        $wallet = Wallet::query()->where('user_id', $booking->attendee_id)->where('currency_code', $payment->currency_code)->sole();
        $this->assertSame($payment->amount_minor, $wallet->balance_minor);
    }

    // ── 6. Provider timeout vs the safe-fallback invariant ──────────────

    public function test_a_timed_out_initiation_never_produces_a_second_accepted_intent(): void
    {
        $booking = $this->reservePaidBooking();

        $results = $this->race([
            ['stripe-initiate', ['booking_id' => $booking->id, 'simulate_timeout' => true]],
            ['stripe-initiate', ['booking_id' => $booking->id, 'simulate_timeout' => false]],
        ]);

        // The safety invariant (§9 of the routing audit: never a
        // duplicate charge after an uncertain/timed-out acceptance) is
        // the only thing this race must guarantee — at most one payment
        // row ever carries an accepted provider_order_id. Both legs
        // safely failing (each losing the row-level race to insert/reset
        // the same idempotency_key) is an acceptable, conservative
        // outcome too, never a bug — liveness (a same-instant fallback
        // succeeding) is deliberately NOT guaranteed, since that is
        // exactly the risky behavior the safe-fallback rule forbids.
        $this->assertLessThanOrEqual(1, BookingPayment::query()->where('booking_id', $booking->id)->whereNotNull('provider_order_id')->count(), json_encode($results));

        // Liveness is proven separately, sequentially: once the race has
        // settled, a fresh retry for the same booking must still succeed
        // — the row is never permanently stuck "in progress".
        $intentId = 'pi_retry_'.bin2hex(random_bytes(6));
        $mock = Mockery::mock(StripeGatewayClient::class);
        $mock->shouldReceive('createPaymentIntent')->andReturn(['id' => $intentId, 'client_secret' => $intentId.'_secret', 'amount' => 4900, 'currency' => 'usd']);
        $this->app->instance(StripeGatewayClient::class, $mock);

        $retry = app(BookingPaymentServiceInterface::class)->initiate($booking->refresh());
        $this->assertSame('pending', $retry->status);
        $this->assertSame(1, BookingPayment::query()->where('booking_id', $booking->id)->whereNotNull('provider_order_id')->count());
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private User $student;

    private User $teacher;

    private function configureStripe(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = self::PUBLISHABLE_KEY;
        $gateways->stripe_secret_key = Crypt::encryptString(self::SECRET_KEY);
        $gateways->stripe_webhook_secret = Crypt::encryptString(self::WEBHOOK_SECRET);
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'stripe';
        app(BookingSettings::class)->save();
    }

    /**
     * booking_types.key must match a compile-time-registered
     * BookingTypeInterface::key() (BookingServiceProvider::registerBookingTypes())
     * — an arbitrary per-test key is rejected by BookingTypeRegistry, and
     * the column is globally unique. Country.iso2 is also globally
     * unique and Country::factory() picks it randomly — with real
     * commits (never rolled back) across 7 test methods in this class,
     * a fresh Country per test carries a real collision risk. Both are
     * therefore created once and reused, real-committed, across every
     * test method in this class; only StudentLessonPrice is seeded fresh
     * per test (harmless — scoped by type+country+currency+duration, and
     * multiple compatible rows are not ambiguous to the resolver here
     * since each test creates its own student/teacher/booking).
     */
    private static ?BookingType $sharedType = null;

    private static ?Country $sharedCountry = null;

    private function reservePaidBooking(): Booking
    {
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'numeric_code' => '840', 'minor_units' => 2, 'status' => 'active']);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        self::$sharedType ??= BookingType::query()->where('key', 'paid_one_to_one')->first()
            ?? BookingType::factory()->paid()->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60, 'max_attendees' => 1]);

        $currency = Currency::query()->where('code', 'USD')->firstOrFail();
        self::$sharedCountry ??= Country::factory()->create(['default_currency_id' => $currency->id]);
        $country = self::$sharedCountry;
        $this->seedStudentLessonPrice(self::$sharedType, $country, $currency, 49.00);
        $this->assignBillingCountry($this->student, $country);
        $this->configureStripe();

        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: self::$sharedType->key,
            studentId: $this->student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ));

        return $booking->refresh();
    }

    /** @return array{0: Booking, 1: BookingPayment} */
    private function initiatedStripePayment(): array
    {
        $booking = $this->reservePaidBooking();

        // provider_order_id is globally unique and rows are real-committed
        // (never rolled back) across every test method in this class — a
        // fixed literal id would collide with an earlier test's row.
        $intentId = 'pi_concurrency_'.bin2hex(random_bytes(6));

        $mock = Mockery::mock(StripeGatewayClient::class);
        $mock->shouldReceive('createPaymentIntent')->andReturn([
            'id' => $intentId, 'client_secret' => $intentId.'_secret', 'amount' => 4900, 'currency' => 'usd',
        ]);
        $this->app->instance(StripeGatewayClient::class, $mock);

        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->sole();

        return [$booking->refresh(), $payment];
    }

    /** @return array<string, mixed> */
    private function webhookArgs(Booking $booking, BookingPayment $payment): array
    {
        return [
            'intent_id' => $payment->provider_order_id,
            'amount_minor' => $payment->amount_minor,
            'currency' => strtolower($payment->currency_code),
            // The PAYMENT reference — see the fix in StripePaymentProvider::createPayment()'s
            // metadata construction (Phase 16C bugfix); a real Stripe
            // webhook's metadata.booking_reference now carries this value,
            // never $booking->reference.
            'booking_reference' => $payment->idempotency_key,
            'webhook_secret' => self::WEBHOOK_SECRET,
        ];
    }
}
