<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Livewire\Frontend\Student\BookingHistory;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\StudentLessonPrice;
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
 * Student-facing Razorpay checkout via Livewire: the wizard's golden
 * path (pay immediately after booking) and BookingHistory's retry path
 * (pay later from the dashboard), plus the 'pay' policy boundary.
 */
class RazorpayCheckoutLivewireTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private const KEY_SECRET = 'test_key_secret';

    private User $teacher;

    private Country $pricedCountry;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], [
            'instructor_status' => 'approved',
            'profile_visibility' => 'public',
        ]);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        // Phase 10.2D-Cleanup-Fix: BookingType::factory()->paid() no
        // longer carries a price — createPaidBookingTypeWithPrice() also
        // seeds the matching StudentLessonPrice (INR, all levels, 60min).
        // withBillingCountry() below points every student at this same
        // country so the wizard's own selectSubject('maths')/selectGrade(5)
        // input resolves against it.
        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR', durationMinutes: 60);
        BookingType::query()->where('key', 'paid_one_to_one')->update(['sort_order' => 1]);
        $this->pricedCountry = $priced['country'];

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString(self::KEY_SECRET);
        $gateways->razorpay_webhook_secret = Crypt::encryptString('webhook_secret');
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();

        $mock = Mockery::mock(RazorpayGatewayClient::class);
        $mock->shouldReceive('createOrder')->andReturn(['id' => 'order_LW1', 'amount' => 49900, 'currency' => 'INR']);
        $this->app->instance(RazorpayGatewayClient::class, $mock);
    }

    private function checkoutSignature(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', "{$orderId}|{$paymentId}", self::KEY_SECRET);
    }

    // A resolvable billing country is required before checkout (Phase
    // 10.2C-Fix's UI-layer profile-completeness gate) — set it here so
    // these tests exercise payment behavior, not the gate itself. Uses
    // $this->pricedCountry (not a fresh random one) so the booking's
    // subject/grade + this country actually resolves against the
    // StudentLessonPrice seeded in setUp() (Phase 10.2D-Cleanup-Fix).
    private function withBillingCountry(User $user): void
    {
        $this->assignBillingCountry($user, $this->pricedCountry);
    }

    public function test_student_can_pay_via_the_booking_wizard(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $this->withBillingCountry($student);

        $start = now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String();

        $component = Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectSubject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->call('selectSlot', $start)
            ->call('submit')
            ->assertSet('step', 6)
            ->assertSee('Pay now');

        $bookingId = $component->get('bookingId');
        $this->assertNotNull($bookingId);

        $component->call('initiatePayment');
        $orderId = $component->get('paymentOrder')['order_id'];
        $this->assertSame('order_LW1', $orderId);

        $signature = $this->checkoutSignature($orderId, 'pay_LW1');
        $component->call('verifyPayment', $orderId, 'pay_LW1', $signature);

        $booking = Booking::query()->findOrFail($bookingId);
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
    }

    public function test_booking_wizard_shows_safe_error_when_no_matrix_price_configured(): void
    {
        // Phase 10.2E: drives the actual wizard submit() flow (not the
        // service layer directly) so the UI-facing error path itself is
        // proven, not just BookingPriceCalculator's exception.
        StudentLessonPrice::query()->delete();

        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $this->withBillingCountry($student);

        $start = now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String();

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectSubject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->call('selectSlot', $start)
            ->call('submit')
            ->assertSet('step', 5)
            ->assertSee('price is not configured');

        $this->assertDatabaseMissing('bookings', ['instructor_id' => $this->teacher->id]);
    }

    public function test_razorpay_checkout_event_payload_has_only_public_fields(): void
    {
        // Phase 10.2E: proves the exact composition of the dispatched
        // browser event — order id, amount, currency, public key,
        // student name/email — and nothing else (no key_secret, no
        // webhook_secret, no raw gateway/admin metadata).
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $this->withBillingCountry($student);

        $start = now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String();

        $component = Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectSubject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->call('selectSlot', $start)
            ->call('submit')
            ->call('initiatePayment');

        $component->assertDispatched(
            'razorpay-checkout-ready',
            orderId: 'order_LW1',
            keyId: 'rzp_test_key_id',
            amountMinor: 49900,
            currency: 'INR',
            name: $student->name,
            email: $student->email,
        );
    }

    public function test_forged_signature_via_livewire_leaves_booking_unpaid(): void
    {
        // Phase 10.2E: "frontend success alone cannot mark booking paid" —
        // drives verifyPayment() with a bad signature through the actual
        // Livewire component a browser would call, not the provider class
        // directly (RazorpayCheckoutTest already covers that layer).
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $this->withBillingCountry($student);

        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(4)->setTime(11, 0)->toImmutable(),
            subject: 'maths',
            grade: 5,
        ));

        $component = Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('initiatePayment');

        $orderId = $component->get('paymentOrder')['order_id'];
        $component->call('verifyPayment', $orderId, 'pay_FORGED', 'not-the-real-signature');

        $this->assertSame(BookingPaymentStatus::Pending, $booking->refresh()->payment_status);
        $this->assertSame(BookingStatus::Pending, $booking->status);
    }

    public function test_student_can_retry_payment_from_booking_history(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $this->withBillingCountry($student);

        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(4)->setTime(11, 0)->toImmutable(),
            subject: 'maths',
            grade: 5,
        ));

        $component = Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('initiatePayment');

        $orderId = $component->get('paymentOrder')['order_id'];
        $signature = $this->checkoutSignature($orderId, 'pay_LW2');
        $component->call('verifyPayment', $orderId, 'pay_LW2', $signature);

        $this->assertSame(BookingPaymentStatus::Paid, $booking->refresh()->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
    }

    public function test_student_cannot_pay_for_another_students_booking(): void
    {
        $owner = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $owner->assignRole('student');
        $this->withBillingCountry($owner);
        $intruder = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $intruder->assignRole('student');

        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $owner->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(5)->setTime(12, 0)->toImmutable(),
            subject: 'maths',
            grade: 5,
        ));

        // The intruder can never load the owner's booking into selectedBooking
        // in the first place — viewBooking() authorizes 'view' before setting it.
        Livewire::actingAs($intruder)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertForbidden();

        $this->assertSame(BookingPaymentStatus::Pending, $booking->refresh()->payment_status);
    }
}
