<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Meetings\FakeMeetingProvider;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3.1 closure — `CreateBookingData`/`BookingRepository::reschedule()`
 * were changed to normalize `startsAt`/`endsAt` to UTC (Eloquent's
 * `immutable_datetime` cast does not itself convert on write — see
 * CreateBookingData::__construct() docblock). This is a cross-booking
 * correctness change, not scoped to the country-aware Demo flow, so it
 * gets its own dedicated, exhaustive coverage: UTC input is provably
 * idempotent (no double conversion), a non-UTC input persists the
 * correct instant on both create and reschedule, instructor/student
 * timezone divergence never affects the persisted instant, and meeting
 * creation receives the exact same instant as the Booking row.
 */
class BookingInstantNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        BookingType::factory()->create(['key' => 'free_demo', 'name' => 'Free Demo', 'duration_minutes' => 30, 'sort_order' => 1]);
    }

    private function teacher(): User
    {
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => 'approved', 'profile_visibility' => 'public']);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject('maths', 1, 12)->create();

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $teacher->id])->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        return $teacher;
    }

    private function student(string $timezone): User
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $student->id], ['timezone' => $timezone]);

        return $student;
    }

    // ── 1. UTC input is idempotent — no double conversion ───────────────────

    public function test_a_utc_startsat_is_not_shifted(): void
    {
        $utcInstant = CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0);

        $data = new CreateBookingData(
            typeKey: 'free_demo',
            studentId: 1,
            instructorId: 1,
            startsAt: $utcInstant,
            durationMinutes: 30,
        );

        $this->assertTrue($data->startsAt->equalTo($utcInstant));
        $this->assertSame($utcInstant->format('Y-m-d H:i:s'), $data->startsAt->format('Y-m-d H:i:s'));

        // Calling ->utc() again (simulating a second normalization pass)
        // must be a pure no-op — proving there is no cumulative shift.
        $this->assertTrue($data->startsAt->utc()->equalTo($utcInstant));
    }

    // ── 2. Non-UTC input persists the correct instant on create ─────────────

    public function test_non_utc_startsat_persists_the_correct_instant_on_create(): void
    {
        $teacher = $this->teacher();
        $student = $this->student('America/New_York');
        $monday = CarbonImmutable::now('UTC')->addWeek();
        while ($monday->dayOfWeek !== Weekday::Monday->value) {
            $monday = $monday->addDay();
        }

        // 09:00 UTC, expressed in the student's own (DST-observing) timezone
        // — the exact offset varies with the run date; only the underlying
        // instant matters here, asserted below.
        $nonUtcInstant = $monday->startOfDay()->addHours(9)->setTimezone('America/New_York');

        $this->actingAs($student);
        $booking = app(WizardBookingServiceInterface::class)->book(new WizardBookingData(
            typeKey: 'free_demo',
            subject: 'maths',
            grade: 6,
            startsAt: $nonUtcInstant,
            timezone: 'America/New_York',
            teacherId: $teacher->id,
        ));

        $this->assertTrue($booking->starts_at->equalTo($nonUtcInstant));
        $this->assertSame('09:00', $booking->starts_at->utc()->format('H:i'));
    }

    // ── 3. Non-UTC input persists the correct instant on reschedule ─────────

    public function test_non_utc_startsat_persists_the_correct_instant_on_reschedule(): void
    {
        $teacher = $this->teacher();
        $student = $this->student('Asia/Kolkata');
        $monday = CarbonImmutable::now('UTC')->addWeek();
        while ($monday->dayOfWeek !== Weekday::Monday->value) {
            $monday = $monday->addDay();
        }
        $originalInstant = $monday->startOfDay()->addHours(9); // 09:00 UTC

        $this->actingAs($student);
        $booking = app(WizardBookingServiceInterface::class)->book(new WizardBookingData(
            typeKey: 'free_demo',
            subject: 'maths',
            grade: 6,
            startsAt: $originalInstant,
            timezone: 'Asia/Kolkata',
            teacherId: $teacher->id,
        ));

        // Reschedule to a slot expressed in the student's own timezone
        // (Kolkata is a fixed +05:30 offset, no DST — deterministic):
        // 11:00 UTC on the following Tuesday == 16:30 IST.
        $tuesday = $monday->addDay();
        $newUtcInstant = $tuesday->startOfDay()->addHours(11);
        $newLocalInstant = $newUtcInstant->setTimezone('Asia/Kolkata');
        $this->assertSame('+05:30', $newLocalInstant->format('P'));

        $rescheduled = app(BookingRepositoryInterface::class)->reschedule(
            $booking,
            $newLocalInstant,
            $newLocalInstant->addMinutes(30),
        );

        $this->assertTrue($rescheduled->starts_at->equalTo($newUtcInstant));
        $this->assertSame('11:00', $rescheduled->starts_at->utc()->format('H:i'));
    }

    // ── 4. Instructor/student timezone divergence never shifts the persisted instant ──

    public function test_instructor_and_student_timezone_divergence_does_not_shift_the_persisted_instant(): void
    {
        // Instructor availability is entered/expanded in UTC (see
        // AvailabilityRepository::windowsFor()); the booking is made by a
        // student in a third, unrelated timezone. The persisted instant
        // must match the instructor's actual UTC-expanded slot exactly,
        // regardless of which timezone the student's client submitted it in.
        $teacher = $this->teacher();
        $student = $this->student('Pacific/Auckland');
        $monday = CarbonImmutable::now('UTC')->addWeek();
        while ($monday->dayOfWeek !== Weekday::Monday->value) {
            $monday = $monday->addDay();
        }
        $instructorSlotUtc = $monday->startOfDay()->addHours(9); // 09:00 UTC, inside the 09:00-17:00 UTC window
        $studentLocalInstant = $instructorSlotUtc->setTimezone('Pacific/Auckland');

        $this->actingAs($student);
        $booking = app(WizardBookingServiceInterface::class)->book(new WizardBookingData(
            typeKey: 'free_demo',
            subject: 'maths',
            grade: 6,
            startsAt: $studentLocalInstant,
            timezone: 'Pacific/Auckland',
            teacherId: $teacher->id,
        ));

        $this->assertTrue($booking->starts_at->equalTo($instructorSlotUtc));
        $this->assertSame('Pacific/Auckland', $booking->timezone);
    }

    // ── 5. Meeting creation receives the exact same instant as the Booking ──

    public function test_meeting_creation_receives_the_same_instant_as_the_booking(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->meetings_enabled = true;
        $settings->create_after_demo_booking_confirmation = true;
        $settings->save();

        $teacher = $this->teacher();
        $student = $this->student('America/New_York');
        $monday = CarbonImmutable::now('UTC')->addWeek();
        while ($monday->dayOfWeek !== Weekday::Monday->value) {
            $monday = $monday->addDay();
        }
        $nonUtcInstant = $monday->startOfDay()->addHours(9)->setTimezone('America/New_York');

        $this->actingAs($student);
        $booking = app(WizardBookingServiceInterface::class)->book(new WizardBookingData(
            typeKey: 'free_demo',
            subject: 'maths',
            grade: 6,
            startsAt: $nonUtcInstant,
            timezone: 'America/New_York',
            teacherId: $teacher->id,
        ));

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking, FakeMeetingProvider::KEY);

        $this->assertNotNull($meeting);
        $this->assertTrue($meeting->starts_at->equalTo($booking->fresh()->starts_at));
    }
}
