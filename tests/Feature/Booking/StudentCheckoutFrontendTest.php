<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Livewire\Frontend\Student\BookingHistory;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\BookingSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Phase 10.2C — authenticated student checkout frontend. The Razorpay
 * checkout flow itself (button → order → Checkout.js → verify) already
 * existed from Phase 10; this phase makes it provider-neutral (it
 * previously hardcoded Razorpay-shaped event keys regardless of which
 * provider was actually configured) and adds the missing safety guard:
 * BookingPaymentService::initiate() never checked booking terminal
 * status, only payment_status.
 */
class StudentCheckoutFrontendTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private const KEY_SECRET = 'test_key_secret';

    private const WEBHOOK_SECRET = 'test_webhook_secret';

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }
    }

    private function student(): User
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        // A resolvable billing country is required before checkout
        // (Phase 10.2C-Fix) — most tests in this file exercise payment
        // behavior, not the profile-completeness gate itself, so it's
        // satisfied by default here; see
        // test_incomplete_profile_blocks_pay_now for the dedicated case.
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => Country::factory()->create()->id]);

        return $student;
    }

    private function configureRazorpay(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString(self::KEY_SECRET);
        $gateways->razorpay_webhook_secret = Crypt::encryptString(self::WEBHOOK_SECRET);
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();
    }

    private function fakeRazorpayOrder(string $orderId = 'order_UI1'): void
    {
        $mock = Mockery::mock(RazorpayGatewayClient::class);
        $mock->shouldReceive('createOrder')->andReturn(['id' => $orderId, 'amount' => 49900, 'currency' => 'INR']);
        $this->app->instance(RazorpayGatewayClient::class, $mock);
    }

    private function paidBooking(User $student, string $currency = 'INR', float $price = 499.00): Booking
    {
        $type = BookingType::factory()->paid()->create([
            'key' => 'paid_one_to_one',
            'duration_minutes' => 60,
        ]);

        // Phase 10.2D-Cleanup-Fix: BookingType::factory()->paid() no
        // longer carries a price — seed a matching StudentLessonPrice for
        // this exact student's own billing country (set by student()).
        $currencyModel = Currency::query()->firstOrCreate(
            ['code' => $currency],
            ['name' => $currency, 'symbol' => $currency, 'numeric_code' => '000', 'minor_units' => 2, 'status' => 'active'],
        );
        $this->seedStudentLessonPrice($type, $student->profile->country, $currencyModel, $price);

        return app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ))->refresh();
    }

    private function freeBooking(User $student): Booking
    {
        BookingType::factory()->create([
            'key' => 'free_demo',
            'is_paid' => false,
            'duration_minutes' => 30,
        ]);

        return app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(11, 0)->toImmutable(),
        ))->refresh();
    }

    // ── 1–6. Pay Now visibility ──────────────────────────────────────

    public function test_student_sees_pay_now_for_own_unpaid_paid_booking(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrder();
        $student = $this->student();
        $booking = $this->paidBooking($student);

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertSee('Pay now');
    }

    public function test_wallet_option_shows_as_coming_soon_for_a_paid_booking(): void
    {
        // Wallet-to-booking debit is explicitly out of scope this phase
        // (spec section E) — the option must be visible but inert.
        $this->configureRazorpay();
        $this->fakeRazorpayOrder();
        $student = $this->student();
        $booking = $this->paidBooking($student);

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertSee('wallet balance')
            ->assertSee('coming soon');
    }

    public function test_student_does_not_see_pay_now_for_free_demo_booking(): void
    {
        $student = $this->student();
        $booking = $this->freeBooking($student);

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertDontSee('Pay now')
            ->assertDontSee('wallet balance');
    }

    public function test_student_does_not_see_pay_now_for_already_paid_booking(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrder();
        $student = $this->student();
        $booking = $this->paidBooking($student);

        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = (string) $booking->refresh()->payment_reference;
        app(BookingPaymentServiceInterface::class)->markPaid($booking, $reference);

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertDontSee('Pay now')
            ->assertSee('Paid');
    }

    public function test_student_does_not_see_pay_now_for_cancelled_booking(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrder();
        $student = $this->student();
        $booking = $this->paidBooking($student);

        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $booking = app(BookingServiceInterface::class)->cancel($booking->refresh(), new CancelBookingData(
            BookingActor::Student,
            'Changed my mind',
        ));

        // The classic race: booking is terminal, payment_status is still Pending.
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertDontSee('Pay now');
    }

    public function test_student_does_not_see_pay_now_for_expired_booking(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrder();
        $student = $this->student();
        $booking = $this->paidBooking($student);

        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $booking->forceFill(['reserved_until' => now()->subMinute()])->save();
        $this->artisan('booking:release-expired')->assertSuccessful();

        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertDontSee('Pay now');
    }

    public function test_no_provider_or_gateway_metadata_is_rendered_to_the_student(): void
    {
        // Phase 10.2E: the wallet-credit banner is the only place
        // BookingPayment is ever queried from student-facing UI, and only
        // as a boolean existence check — confirms no raw provider_order_id/
        // provider_payment_id/metadata ever reaches the rendered HTML.
        $this->configureRazorpay();
        $this->fakeRazorpayOrder('order_META1');
        $student = $this->student();
        $booking = $this->paidBooking($student);

        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $html = Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->html();

        $this->assertStringNotContainsString('order_META1', $html);
        $this->assertStringNotContainsString('provider_order_id', $html);
        $this->assertStringNotContainsString('provider_payment_id', $html);
    }

    public function test_another_student_cannot_view_or_pay_for_this_booking(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrder();
        $owner = $this->student();
        $intruder = $this->student();
        $booking = $this->paidBooking($owner);

        Livewire::actingAs($intruder)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertForbidden();
    }

    public function test_incomplete_profile_blocks_pay_now(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrder();

        // A student with no country on their profile — the
        // billing-profile gate this file's student() helper normally
        // satisfies for every other test. Booking *creation* itself
        // still needs a resolvable country (Phase 10.2D's pricing
        // matrix is keyed by billing country), so the country is
        // cleared only after the booking exists — initiatePayment()'s
        // profile-completeness gate is what this test actually proves,
        // not whether booking creation needs a country (it does, and
        // that's covered elsewhere).
        $student = $this->student();
        $booking = $this->paidBooking($student);
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => null]);
        // paidBooking() lazy-loaded and cached $student->profile (with the
        // old, non-null country_id) on this exact object — Livewire's
        // actingAs() reuses this object as-is, so the stale relation must
        // be dropped or the check below reads the cached value, not the
        // DB write above.
        $student->unsetRelation('profile');

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('initiatePayment')
            ->assertSee('complete your profile');

        $this->assertSame(BookingPaymentStatus::Pending, $booking->refresh()->payment_status);
        $this->assertNull($booking->refresh()->payment_reference);
    }

    // ── Backend safety: terminal booking cannot initiate a new payment ──

    public function test_cancelled_booking_cannot_initiate_a_new_payment(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrder();
        $student = $this->student();
        $booking = $this->paidBooking($student);

        $booking = app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(
            BookingActor::Student,
            'Changed my mind',
        ));

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/cancelled and cannot accept a new payment/i');

        app(BookingPaymentServiceInterface::class)->initiate($booking->refresh());
    }

    public function test_duplicate_payment_initiation_is_idempotent(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrder('order_DUP1');
        $student = $this->student();
        $booking = $this->paidBooking($student);

        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $firstReference = $booking->refresh()->payment_reference;

        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $secondReference = $booking->refresh()->payment_reference;

        $this->assertSame($firstReference, $secondReference);
    }

    // ── Fake provider — local/testing simulation ─────────────────────

    public function test_fake_payment_success_works_in_testing_and_confirms_booking(): void
    {
        app(BookingSettings::class)->payment_provider = 'fake';
        app(BookingSettings::class)->save();

        $student = $this->student();
        $booking = $this->paidBooking($student);

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('initiatePayment')
            ->assertSee('Simulate success')
            ->call('simulateFakePayment', true);

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
    }

    public function test_fake_payment_failure_leaves_booking_unpaid(): void
    {
        app(BookingSettings::class)->payment_provider = 'fake';
        app(BookingSettings::class)->save();

        $student = $this->student();
        $booking = $this->paidBooking($student);

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('initiatePayment')
            ->call('simulateFakePayment', false);

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Failed, $booking->payment_status);
    }

    public function test_simulate_fake_payment_is_a_no_op_outside_local_or_testing(): void
    {
        app(BookingSettings::class)->payment_provider = 'fake';
        app(BookingSettings::class)->save();

        $student = $this->student();
        $booking = $this->paidBooking($student);

        $this->app->detectEnvironment(fn (): string => 'production');

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('initiatePayment')
            ->call('simulateFakePayment', true);

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
    }

    // ── Stripe — safe deferred message, no secrets ───────────────────

    public function test_stripe_selected_shows_safe_coming_soon_message_and_leaks_no_secret(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_abc123';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test_abc123');
        $gateways->stripe_webhook_secret = Crypt::encryptString('whsec_abc123');
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'stripe';
        app(BookingSettings::class)->save();

        $mock = Mockery::mock(StripeGatewayClient::class);
        $mock->shouldReceive('createPaymentIntent')->andReturn(['id' => 'pi_UI1', 'client_secret' => 'pi_UI1_secret_xyz', 'amount' => 49900, 'currency' => 'inr']);
        $mock->shouldReceive('retrievePaymentIntent')->andReturn(['id' => 'pi_UI1', 'client_secret' => 'pi_UI1_secret_xyz', 'amount' => 49900, 'currency' => 'inr']);
        $this->app->instance(StripeGatewayClient::class, $mock);

        $student = $this->student();
        $type = BookingType::factory()->paid()->create([
            'key' => 'paid_one_to_one',
            'duration_minutes' => 60,
        ]);
        $usd = Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'USD', 'symbol' => '$', 'numeric_code' => '840', 'minor_units' => 2, 'status' => 'active']);
        $this->seedStudentLessonPrice($type, $student->profile->country, $usd, 499.00);

        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ))->refresh();

        $component = Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('initiatePayment')
            ->assertSee('coming soon');

        $html = $component->html();
        $this->assertStringNotContainsString('sk_test_abc123', $html);
        $this->assertStringNotContainsString('whsec_abc123', $html);
        $this->assertStringNotContainsString('pi_UI1_secret_xyz', $html);

        // Phase 10.2E: assertDontSee on rendered HTML only proves the
        // Blade template never echoes it — Livewire hydrates every public
        // property (including $paymentOrder) into the page's snapshot for
        // a real browser, which Livewire::test()->html() does not
        // reproduce. checkoutPayload() for Stripe legitimately returns a
        // live, usable client_secret (Stripe's own createPaymentIntent
        // response) — assert directly against the component's public
        // state that it never reaches $paymentOrder while the Stripe
        // frontend stays deferred, not just that the template doesn't
        // print it.
        $paymentOrder = $component->get('paymentOrder');
        $this->assertSame(['provider' => 'stripe'], $paymentOrder);
        $this->assertArrayNotHasKey('client_secret', $paymentOrder);
        $this->assertArrayNotHasKey('publishable_key', $paymentOrder);
        $this->assertArrayNotHasKey('payment_intent_id', $paymentOrder);
    }
}
