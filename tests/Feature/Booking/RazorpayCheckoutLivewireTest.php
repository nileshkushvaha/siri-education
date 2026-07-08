<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Livewire\Frontend\Student\BookingHistory;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\BookingSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Student-facing Razorpay checkout via Livewire: the wizard's golden
 * path (pay immediately after booking) and BookingHistory's retry path
 * (pay later from the dashboard), plus the 'pay' policy boundary.
 */
class RazorpayCheckoutLivewireTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_SECRET = 'test_key_secret';

    private User $teacher;

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

        BookingType::factory()->paid(499.00, 'INR')->create([
            'key' => 'paid_one_to_one',
            'duration_minutes' => 60,
            'max_attendees' => 1,
            'sort_order' => 1,
        ]);

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString(self::KEY_SECRET);
        $gateways->razorpay_webhook_secret = Crypt::encryptString('webhook_secret');
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();

        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_LW1'], 200),
        ]);
    }

    private function checkoutSignature(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', "{$orderId}|{$paymentId}", self::KEY_SECRET);
    }

    public function test_student_can_pay_via_the_booking_wizard(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $start = now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String();

        $component = Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectSubject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->call('selectSlot', $start)
            ->set('name', $student->name)
            ->set('email', $student->email)
            ->call('review')
            ->call('submit')
            ->assertSet('step', 7)
            ->assertSee('Pay with Razorpay');

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

    public function test_student_can_retry_payment_from_booking_history(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(4)->setTime(11, 0)->toImmutable(),
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
        $intruder = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $intruder->assignRole('student');

        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $owner->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(5)->setTime(12, 0)->toImmutable(),
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
