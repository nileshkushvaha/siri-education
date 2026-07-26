<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Enums\StudentStatus;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Student\StudentLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * student_status must be exactly Active for ordinary student business
 * actions (booking creation, reschedule, cancellation). Registered,
 * Suspended, Archived,
 * a missing profile, AND a null student_status are all rejected — null
 * is invalid/ambiguous data, never an implicit grant of capability. See
 * StudentLifecycleService::isEligibleForStudentActions().
 */
class StudentLifecycleBookingEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private BookingType $demoType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->demoType = BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    private function makeTeacher(): User
    {
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject('maths', 1, 12)->create();

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()
                ->state(['teacher_id' => $teacher->id])
                ->forDay($day)
                ->between('09:00:00', '17:00:00')
                ->create();
        }

        return $teacher;
    }

    private function makeStudent(StudentStatus $status): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => $status]);

        return $student;
    }

    private function slot(int $daysAhead): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDays($daysAhead)->setTime(10, 0);
    }

    // ── 8. Suspended student cannot create a booking ─────────────────────────

    public function test_suspended_student_cannot_create_a_booking(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent(StudentStatus::Suspended);

        $this->expectException(BookingException::class);

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: $this->slot(3),
            durationMinutes: 30,
        ));
    }

    public function test_archived_student_cannot_create_a_booking(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent(StudentStatus::Archived);

        $this->expectException(BookingException::class);

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: $this->slot(3),
            durationMinutes: 30,
        ));
    }

    /**
     * Active is authoritative, so a Registered (not-yet-Active) student
     * is rejected too, not just Suspended/Archived — see
     * StudentLifecycleService::isEligibleForStudentActions().
     */
    public function test_registered_student_cannot_create_a_booking(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent(StudentStatus::Registered);

        $this->expectException(BookingException::class);

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: $this->slot(3),
            durationMinutes: 30,
        ));
    }

    /**
     * A null student_status (never processed by the lifecycle — e.g.
     * the student role assigned without going through registration) is
     * invalid/ambiguous data, never an implicit pass.
     */
    public function test_a_student_with_no_lifecycle_status_cannot_create_a_booking(): void
    {
        $teacher = $this->makeTeacher();
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $this->expectException(BookingException::class);

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: $this->slot(3),
            durationMinutes: 30,
        ));
    }

    /** A student role with no profile row at all (should never happen structurally, but defensively) is also rejected. */
    public function test_a_student_with_a_missing_profile_cannot_create_a_booking(): void
    {
        $teacher = $this->makeTeacher();
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->delete();

        $this->expectException(BookingException::class);

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: $this->slot(3),
            durationMinutes: 30,
        ));
    }

    public function test_active_student_can_create_a_booking(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent(StudentStatus::Active);

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: $this->slot(3),
            durationMinutes: 30,
        ));

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => BookingStatus::Confirmed->value]);
    }

    // ── 9. Suspended student cannot reschedule or cancel ─────────────────────

    public function test_suspended_student_cannot_reschedule_their_booking(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent(StudentStatus::Active);

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: $this->slot(3),
            durationMinutes: 30,
        ));

        $student->profile()->update(['student_status' => StudentStatus::Suspended]);

        $this->expectException(BookingException::class);

        app(BookingServiceInterface::class)->reschedule($booking->fresh(), new RescheduleBookingData(
            startsAt: $this->slot(5),
            actor: BookingActor::Student,
        ));
    }

    public function test_suspended_student_cannot_cancel_their_booking(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent(StudentStatus::Active);

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: $this->slot(3),
            durationMinutes: 30,
        ));

        $student->profile()->update(['student_status' => StudentStatus::Suspended]);

        $this->expectException(BookingException::class);

        app(BookingServiceInterface::class)->cancel($booking->fresh(), new CancelBookingData(BookingActor::Student, 'Trying to cancel while suspended.'));
    }

    /** An instructor/admin cancelling on the suspended student's behalf must not be blocked — only STUDENT-initiated actions are restricted. */
    public function test_instructor_can_still_cancel_a_suspended_students_booking(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent(StudentStatus::Active);

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: $this->slot(3),
            durationMinutes: 30,
        ));

        $student->profile()->update(['student_status' => StudentStatus::Suspended]);

        $cancelled = app(BookingServiceInterface::class)->cancel($booking->fresh(), new CancelBookingData(BookingActor::Instructor, 'Instructor-initiated cancellation.'));

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
    }

    // ── 10. Suspension does not mutate existing bookings ─────────────────────

    public function test_suspending_a_student_does_not_mutate_their_existing_bookings(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent(StudentStatus::Active);

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: $this->slot(3),
            durationMinutes: 30,
        ));

        app(StudentLifecycleService::class)->suspend(
            $student,
            $this->authorizedAdmin(),
            'Reason for suspension.',
        );

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => BookingStatus::Confirmed->value,
        ]);
    }

    private function authorizedAdmin(): User
    {
        Permission::firstOrCreate([
            'name' => StudentLifecycleService::SUSPEND_PERMISSION,
            'guard_name' => 'web',
        ]);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo(StudentLifecycleService::SUSPEND_PERMISSION);

        return $admin;
    }
}
