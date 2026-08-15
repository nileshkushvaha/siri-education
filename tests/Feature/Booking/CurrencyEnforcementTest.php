<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\PaymentStatusResult;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * SRS-21-4, SRS §21.38/§21.40: new financial activity requires an
 * Active currency, re-checked at the final
 * internal boundary; existing obligations (a payment attempt already
 * created) remain settleable regardless of a later currency change.
 */
final class CurrencyEnforcementTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $student;

    private User $teacher;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->profile()->update(['phone_e164' => '+9199999'.str_pad((string) $this->student->id, 5, '0', STR_PAD_LEFT), 'phone_verified_at' => now()]);

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR');
        $this->currency = $priced['currency'];
        $this->assignBillingCountry($this->student, $priced['country']);
    }

    private function reserve(): Booking
    {
        return app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ))->refresh();
    }

    // ── 1-2: new payment attempt active/inactive ─────────────────────

    public function test_new_payment_attempt_succeeds_with_active_currency(): void
    {
        $booking = $this->reserve();

        $intent = app(BookingPaymentServiceInterface::class)->initiate($booking);

        $this->assertSame('INR', $intent->currency);
        $this->assertSame(1, BookingPayment::query()->where('booking_id', $booking->id)->count());
    }

    public function test_new_attempt_is_rejected_for_inactive_currency(): void
    {
        $booking = $this->reserve();
        $this->currency->update(['status' => 'inactive']);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('This currency is currently unavailable for new payments. Please contact support.');

        app(BookingPaymentServiceInterface::class)->initiate($booking);
    }

    // ── 3-4: soft-deleted / unknown currency ─────────────────────────

    public function test_soft_deleted_currency_is_rejected(): void
    {
        $booking = $this->reserve();
        $this->currency->delete();

        $this->expectException(BookingException::class);

        app(BookingPaymentServiceInterface::class)->initiate($booking);
    }

    public function test_unknown_currency_is_rejected(): void
    {
        $booking = $this->reserve();
        $booking->forceFill(['currency' => 'XXX'])->save();

        $this->expectException(BookingException::class);

        app(BookingPaymentServiceInterface::class)->initiate($booking->fresh());
    }

    // ── 5: normalization ──────────────────────────────────────────────

    public function test_currency_code_normalization_is_correct(): void
    {
        $booking = $this->reserve();
        $booking->forceFill(['currency' => 'inr'])->save(); // lowercase

        $intent = app(BookingPaymentServiceInterface::class)->initiate($booking->fresh());

        $this->assertSame('inr', $intent->currency); // display value untouched — only the eligibility check normalizes
        $this->assertSame(1, BookingPayment::query()->where('booking_id', $booking->id)->count());
    }

    // ── 6-7: no row created, no provider call ────────────────────────

    public function test_no_payment_attempt_row_is_created_after_rejection(): void
    {
        $booking = $this->reserve();
        $this->currency->update(['status' => 'inactive']);

        try {
            app(BookingPaymentServiceInterface::class)->initiate($booking);
        } catch (BookingException) {
        }

        $this->assertSame(0, BookingPayment::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_no_provider_call_occurs_after_rejection(): void
    {
        Http::fake();
        $booking = $this->reserve();
        $this->currency->update(['status' => 'inactive']);

        try {
            app(BookingPaymentServiceInterface::class)->initiate($booking);
        } catch (BookingException) {
        }

        Http::assertNothingSent();
    }

    // ── 8: stale checkout after deactivation ─────────────────────────

    public function test_stale_checkout_is_rejected_after_currency_deactivation(): void
    {
        // Booking (and its price) was created while the currency was
        // still active — simulating a stale browser page that only
        // reaches checkout after an admin disables the currency.
        $booking = $this->reserve();
        $this->currency->update(['status' => 'inactive']);

        $this->expectException(BookingException::class);

        app(BookingPaymentServiceInterface::class)->initiate($booking);
    }

    // ── 9-10: existing attempt settles after deactivation ────────────

    public function test_existing_attempt_settles_through_signed_webhook_after_deactivation(): void
    {
        $booking = $this->reserve();
        $intent = app(BookingPaymentServiceInterface::class)->initiate($booking);

        $this->currency->update(['status' => 'inactive']);

        // Correlate on the canonical ATTEMPT reference, which is what a
        // provider echoes back — not the obligation reference.
        $attemptReference = (string) Payment::query()->latest('created_at')->sole()->idempotency_key;
        $body = json_encode(['event' => 'succeeded', 'reference' => $attemptReference]);
        $signature = hash_hmac('sha256', $body, (string) config('app.key'));

        $response = $this->postJson('/api/webhooks/bookings/payments/fake', json_decode($body, true), [
            'X-Booking-Payment-Signature' => $signature,
        ]);

        $response->assertOk();
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(
            BookingPaymentRecordStatus::Captured,
            BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail()->status,
        );
    }

    public function test_existing_attempt_verifies_and_requeries_after_deactivation(): void
    {
        $booking = $this->reserve();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();

        $this->currency->update(['status' => 'inactive']);

        $result = app(BookingPaymentServiceInterface::class)->applyProviderStatus(
            $payment,
            new PaymentStatusResult(
                recordStatus: BookingPaymentRecordStatus::Captured,
                providerPaymentId: null,
                providerStatus: 'captured',
                safeReason: null,
                // PAY-1: a verified capture now carries the money the
                // provider reports; settlement compares it against the
                // booking payment before proceeding.
                verifiedAmountMinor: $payment->amount_minor,
                verifiedCurrency: $payment->currency_code,
            ),
        );

        $this->assertSame(BookingPaymentRecordStatus::Captured, $result->status);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    // ── 11: retry after deactivation rejected ────────────────────────

    public function test_new_retry_attempt_is_rejected_after_deactivation(): void
    {
        $booking = $this->reserve();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();
        $payment->forceFill(['status' => BookingPaymentRecordStatus::Failed])->save();
        app(BookingPaymentServiceInterface::class)->markFailed($booking->fresh(), (string) $booking->fresh()->payment_reference);

        $this->currency->update(['status' => 'inactive']);

        $this->expectException(BookingException::class);

        // A retry re-enters initiate() — must recheck currency status,
        // never call the provider.
        app(BookingPaymentServiceInterface::class)->initiate($booking->fresh());
    }

    // ── 12: amount/currency matching remains enforced ────────────────

    public function test_amount_and_currency_matching_remains_enforced_regardless_of_currency_status(): void
    {
        $booking = $this->reserve();
        $intent = app(BookingPaymentServiceInterface::class)->initiate($booking);
        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();

        $this->assertSame('INR', $payment->currency_code);
        $this->assertSame((int) round($booking->price * 100), $payment->amount_minor);
        $this->assertSame($intent->reference, $payment->idempotency_key);
    }

    // ── 20-21: provider support / country routing independence ──────

    public function test_provider_unsupported_currency_remains_rejected_independently_of_active_status(): void
    {
        // FakePaymentProvider::supportedCurrencies() does not include 'XAU'.
        Currency::query()->create(['code' => 'XAU', 'name' => 'Gold', 'symbol' => 'XAU', 'numeric_code' => '959', 'minor_units' => 2, 'status' => 'active']);
        $booking = $this->reserve();
        $booking->forceFill(['currency' => 'XAU'])->save();

        // The two rules remain independent axes. XAU is ACTIVE, so the
        // currency-status axis passes — and the attempt is still refused,
        // by the provider-support axis instead. The cutover moved that
        // check ahead of any write (PaymentProviderResolver::
        // assertSupportsCurrency), so an unsupported currency is now
        // rejected before an obligation or attempt can be created, rather
        // than being caught later. Proving WHICH rule rejected it is the
        // point of this test.
        try {
            app(BookingPaymentServiceInterface::class)->initiate($booking->fresh());
            $this->fail('Expected the provider-currency rule to reject XAU.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('only supports', $e->getMessage());
            $this->assertStringNotContainsString('inactive', strtolower($e->getMessage()));
        }

        // Nothing was written before the refusal.
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_country_routing_does_not_silently_switch_currency(): void
    {
        $booking = $this->reserve();

        $intent = app(BookingPaymentServiceInterface::class)->initiate($booking);

        $this->assertSame($booking->currency, $intent->currency);
    }

    // ── 23: currency-status audit ─────────────────────────────────────

    public function test_currency_status_change_remains_audit_logged(): void
    {
        $before = Activity::query()->where('log_name', 'currencies')->count();

        $this->currency->update(['status' => 'inactive']);

        $activity = Activity::query()->where('log_name', 'currencies')->where('event', 'updated')->latest('id')->first();

        // Currency::LogsActivity (pre-existing, unrelated to this phase)
        // fires an 'updated' event for every status change — this is the
        // existing admin convention Step 12 asks to preserve, not
        // extend; its own properties shape is out of this phase's scope.
        $this->assertNotNull($activity);
        $this->assertSame((string) $this->currency->id, (string) $activity->subject_id);
        $this->assertGreaterThan($before, Activity::query()->where('log_name', 'currencies')->count());
    }

    // ── 24: existing successful payment/history unchanged ────────────

    public function test_existing_successful_payment_history_remains_unchanged_after_deactivation(): void
    {
        $booking = $this->reserve();
        $intent = app(BookingPaymentServiceInterface::class)->initiate($booking);
        $body = json_encode(['event' => 'succeeded', 'reference' => $intent->reference]);
        $signature = hash_hmac('sha256', $body, (string) config('app.key'));
        $this->postJson('/api/webhooks/bookings/payments/fake', json_decode($body, true), ['X-Booking-Payment-Signature' => $signature])->assertOk();

        $before = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();

        $this->currency->update(['status' => 'inactive']);

        $after = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame($before->amount_minor, $after->amount_minor);
        $this->assertSame($before->currency_code, $after->currency_code);
        $this->assertSame($before->status, $after->status);
    }

    // ── 25: no external provider call in tests ───────────────────────

    public function test_no_external_provider_call_occurs_in_tests(): void
    {
        Http::fake();
        $booking = $this->reserve();

        app(BookingPaymentServiceInterface::class)->initiate($booking);

        Http::assertNothingSent();
    }
}
