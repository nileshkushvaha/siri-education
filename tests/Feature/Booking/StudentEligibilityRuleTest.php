<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\AuthenticationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 10.2C-Fix — VerifiedActiveStudentRule / AuthenticatedAttendeeRule:
 * booking creation requires an authenticated, active, (optionally)
 * email-verified student. Billing-profile completeness is deliberately
 * NOT enforced here (see BookingService::GLOBAL_RULES comment) — it's
 * checked at payment-initiation UI time instead (see
 * StudentCheckoutFrontendTest::test_incomplete_profile_blocks_pay_now).
 */
class StudentEligibilityRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30, 'max_attendees' => 1]);
    }

    private function slot(int $daysAhead = 3): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDays($daysAhead)->setTime(10, 0);
    }

    private function bookingData(User $attendee): CreateBookingData
    {
        return new CreateBookingData(
            typeKey: 'free_demo',
            attendeeId: $attendee->id,
            hostId: $this->teacher->id,
            startsAt: $this->slot(),
            durationMinutes: 30,
        );
    }

    public function test_active_verified_student_can_book(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $booking = app(BookingServiceInterface::class)->request($this->bookingData($student));

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'attendee_id' => $student->id]);
    }

    public function test_inactive_student_cannot_book(): void
    {
        $student = User::factory()->create(['status' => 'pending_verification']);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Your account is not active');

        app(BookingServiceInterface::class)->request($this->bookingData($student));
    }

    public function test_suspended_student_cannot_book(): void
    {
        $student = User::factory()->create(['status' => 'suspended']);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Your account is not active');

        app(BookingServiceInterface::class)->request($this->bookingData($student));
    }

    public function test_unverified_email_student_cannot_book_when_verification_required(): void
    {
        app(AuthenticationSettings::class)->email_verification_required = true;
        app(AuthenticationSettings::class)->save();

        $student = User::factory()->unverified()->create(['status' => User::STATUS_ACTIVE]);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('verify your email');

        app(BookingServiceInterface::class)->request($this->bookingData($student));
    }

    public function test_unverified_email_student_can_book_when_verification_not_required(): void
    {
        app(AuthenticationSettings::class)->email_verification_required = false;
        app(AuthenticationSettings::class)->save();

        $student = User::factory()->unverified()->create(['status' => User::STATUS_ACTIVE]);

        $booking = app(BookingServiceInterface::class)->request($this->bookingData($student));

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'attendee_id' => $student->id]);
    }

    public function test_free_booking_creation_succeeds_without_a_billing_country(): void
    {
        // Confirms the deliberate scoping decision: a missing billing
        // country never blocks *booking creation* — only paid checkout,
        // and only at the UI layer (BookingWizard/BookingHistory).
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => null]);

        $booking = app(BookingServiceInterface::class)->request($this->bookingData($student));

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'attendee_id' => $student->id]);
    }
}
