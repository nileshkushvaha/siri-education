<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingJoinAvailability;
use App\Booking\Repositories\BookingRepository;
use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\BookingType;
use App\Models\User;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * studentJoinStatesFor(): the list-safe student join decision — the same
 * predicates as studentJoinUrlFor(), one lifecycle read per call, the
 * gateway link only while the window is open — and the repository's
 * "upcoming" keeping a lesson in progress on the student's schedule.
 */
final class StudentJoinStateTest extends TestCase
{
    use RefreshDatabase;

    private const string JOIN_URL = 'https://us05web.zoom.us/j/82122025909?pwd=participant';

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $settings = app(MeetingSettings::class);
        $settings->student_join_url_visible = true;
        $settings->meeting_link_visible_before_minutes = 15;
        $settings->meeting_link_visible_after_minutes = 15;
        $settings->save();

        $this->student = $this->activeStudent();
    }

    private function activeStudent(): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Active, 'timezone' => 'UTC']);

        return $student;
    }

    /** A confirmed one-hour lesson with a created Zoom meeting. */
    private function lessonAt(CarbonImmutable $startsAt, ?User $student = null): Booking
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $type = BookingType::query()->where('key', 'free_demo')->first()
            ?? BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 60]);

        $booking = Booking::factory()->for($type, 'type')->create([
            'student_id' => ($student ?? $this->student)->id,
            'instructor_id' => $instructor->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'timezone' => 'UTC',
        ]);

        BookingMeeting::factory()->zoom()->created(self::JOIN_URL)->create([
            'booking_id' => $booking->id,
            'password' => 'pass1234',
            'provider_meeting_id' => '82122025909',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
        ]);

        return $booking->fresh(['meeting', 'lesson']);
    }

    private function service(): BookingMeetingServiceInterface
    {
        return app(BookingMeetingServiceInterface::class);
    }

    public function test_available_state_carries_the_gateway_link_and_passcode_never_the_provider_url(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-13 19:05:00', 'UTC'));
        $booking = $this->lessonAt(CarbonImmutable::parse('2026-09-13 19:00:00', 'UTC'));

        $state = $this->service()->studentJoinStateFor($booking, $this->student);

        $this->assertTrue($state->isAvailable());
        $this->assertSame(route('dashboard.meetings.join', $booking), $state->joinUrl);
        $this->assertSame('pass1234', $state->passcode);
        $this->assertStringNotContainsString(self::JOIN_URL, (string) json_encode($state));
        $this->assertTrue($state->poll);
        $this->assertFalse($state->ended);
    }

    public function test_too_early_state_names_when_the_window_opens_and_carries_no_link(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-13 18:00:00', 'UTC'));
        $booking = $this->lessonAt(CarbonImmutable::parse('2026-09-13 19:00:00', 'UTC'));

        $state = $this->service()->studentJoinStateFor($booking, $this->student);

        $this->assertSame(MeetingJoinAvailability::TooEarly, $state->availability);
        $this->assertNull($state->joinUrl);
        $this->assertNull($state->passcode);
        $this->assertTrue($state->opensAt->equalTo(CarbonImmutable::parse('2026-09-13 18:45:00', 'UTC')));
        $this->assertTrue($state->closesAt->equalTo(CarbonImmutable::parse('2026-09-13 20:15:00', 'UTC')));
        $this->assertTrue($state->poll, 'polls from an hour before the window opens');
    }

    public function test_poll_is_off_well_before_the_window_and_after_it_closed(): void
    {
        $lessonStart = CarbonImmutable::parse('2026-09-13 19:00:00', 'UTC');

        $this->travelTo($lessonStart->subHours(3));
        $booking = $this->lessonAt($lessonStart);
        $this->assertFalse($this->service()->studentJoinStateFor($booking, $this->student)->poll);

        $this->travelTo($lessonStart->addHours(2));
        $state = $this->service()->studentJoinStateFor($booking->fresh(['meeting', 'lesson']), $this->student);
        $this->assertFalse($state->poll);
        $this->assertSame(MeetingJoinAvailability::Unavailable, $state->availability);
        $this->assertTrue($state->ended);
    }

    public function test_states_for_a_list_read_the_student_lifecycle_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-13 19:05:00', 'UTC'));
        $bookings = collect([
            $this->lessonAt(CarbonImmutable::parse('2026-09-13 19:00:00', 'UTC')),
            $this->lessonAt(CarbonImmutable::parse('2026-09-14 19:00:00', 'UTC')),
            $this->lessonAt(CarbonImmutable::parse('2026-09-15 19:00:00', 'UTC')),
        ]);

        DB::enableQueryLog();
        $states = $this->service()->studentJoinStatesFor($bookings, $this->student);
        $profileReads = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'user_profiles'))->count();
        DB::disableQueryLog();

        $this->assertCount(3, $states);
        $this->assertSame(1, $profileReads);
        $this->assertTrue($states[$bookings[0]->id]->isAvailable());
        $this->assertSame(MeetingJoinAvailability::TooEarly, $states[$bookings[1]->id]->availability);
    }

    public function test_a_suspended_student_gets_unavailable_for_every_booking(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-13 19:05:00', 'UTC'));
        $booking = $this->lessonAt(CarbonImmutable::parse('2026-09-13 19:00:00', 'UTC'));
        $this->student->profile()->update(['student_status' => StudentStatus::Suspended]);

        $state = $this->service()->studentJoinStateFor($booking, $this->student->fresh());

        $this->assertSame(MeetingJoinAvailability::Unavailable, $state->availability);
        $this->assertNull($state->joinUrl);
        $this->assertNull($state->opensAt);
    }

    public function test_another_students_booking_is_unavailable_even_inside_the_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-13 19:05:00', 'UTC'));
        $other = $this->activeStudent();
        $booking = $this->lessonAt(CarbonImmutable::parse('2026-09-13 19:00:00', 'UTC'), $other);

        $state = $this->service()->studentJoinStateFor($booking, $this->student);

        $this->assertSame(MeetingJoinAvailability::Unavailable, $state->availability);
        $this->assertNull($state->joinUrl);
        $this->assertTrue($this->service()->studentJoinStateFor($booking, $other)->isAvailable());
    }

    public function test_upcoming_for_user_keeps_a_lesson_in_progress_and_drops_an_ended_one(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-13 19:10:00', 'UTC'));
        $inProgress = $this->lessonAt(CarbonImmutable::parse('2026-09-13 19:00:00', 'UTC'));
        $ended = $this->lessonAt(CarbonImmutable::parse('2026-09-13 17:00:00', 'UTC'));
        $tomorrow = $this->lessonAt(CarbonImmutable::parse('2026-09-14 19:00:00', 'UTC'));

        $upcoming = app(BookingRepository::class)->upcomingForUser($this->student->id);

        $this->assertSame([$inProgress->id, $tomorrow->id], $upcoming->pluck('id')->all());
        $this->assertFalse($upcoming->contains('id', $ended->id));
        $this->assertTrue($upcoming->first()->relationLoaded('meeting'));
        $this->assertTrue($upcoming->first()->relationLoaded('lesson'));
    }
}
