<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Booking\Enums\BookingStatus;
use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Livewire\Frontend\Student\BookingHistory;
use App\Livewire\Frontend\Student\DashboardOverview;
use App\Livewire\Frontend\Student\UpcomingClasses;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\BookingType;
use App\Models\User;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A student on a recurring schedule finds today's join button without
 * digging: on the dashboard (hero + upcoming list), pinned above My
 * Bookings, and on Upcoming Classes — all from the one authoritative
 * join state, never the legacy meeting_url column.
 */
final class StudentScheduleSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private const string JOIN_URL = 'https://us05web.zoom.us/j/82122025909?pwd=participant';

    private const string LEGACY_URL = 'https://legacy.example.test/should-never-render';

    private User $student;

    private User $instructor;

    private BookingType $type;

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

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
        $this->student->profile()->update(['student_status' => StudentStatus::Active, 'timezone' => 'UTC']);

        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->instructor->assignRole('instructor');
        $this->instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $this->type = BookingType::factory()->create(['key' => 'free_demo', 'name' => 'Maths Tutoring', 'duration_minutes' => 60]);
    }

    private function lessonAt(CarbonImmutable $startsAt, bool $withMeeting = true): Booking
    {
        $booking = Booking::factory()->for($this->type, 'type')->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'timezone' => 'UTC',
            'meeting_url' => self::LEGACY_URL,
        ]);

        if ($withMeeting) {
            BookingMeeting::factory()->zoom()->created(self::JOIN_URL)->create([
                'booking_id' => $booking->id,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addHour(),
            ]);
        }

        return $booking->fresh();
    }

    /** Fifteen daily 19:00 UTC lessons starting today, 13 Sep 2026. */
    private function dailySeries(): Booking
    {
        $today = null;
        foreach (range(0, 14) as $day) {
            $booking = $this->lessonAt(CarbonImmutable::parse('2026-09-13 19:00:00', 'UTC')->addDays($day));
            $today ??= $booking;
        }

        return $today;
    }

    // ── Dashboard ───────────────────────────────────────────────────────────

    public function test_dashboard_hero_keeps_the_lesson_in_progress_with_its_join_button(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-13 19:05:00', 'UTC'));
        $today = $this->dailySeries();

        Livewire::actingAs($this->student)
            ->test(DashboardOverview::class)
            ->assertSee('Your lesson now')
            ->assertSee('Join the lesson')
            ->assertSee(route('dashboard.meetings.join', $today), false)
            ->assertSee('wire:poll.60s', false)
            ->assertDontSee(self::JOIN_URL)
            ->assertDontSee(self::LEGACY_URL)
            ->assertSee('Upcoming classes')
            ->assertSee(route('dashboard.upcoming-classes'), false);
    }

    public function test_dashboard_hero_says_when_joining_opens_before_the_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-13 17:00:00', 'UTC'));
        $this->dailySeries();

        Livewire::actingAs($this->student)
            ->test(DashboardOverview::class)
            ->assertSee('Your lesson today')
            ->assertSee('Joining opens at')
            ->assertDontSee('Join link available near lesson time')
            ->assertDontSee('Join the lesson')
            ->assertDontSee(self::JOIN_URL);
    }

    // ── My Bookings ─────────────────────────────────────────────────────────

    public function test_my_bookings_pins_todays_lesson_above_the_list_on_any_filter_and_page(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-13 19:05:00', 'UTC'));
        $today = $this->dailySeries();

        // Newest first at 10 per page, filtered to a status with no rows:
        // today's lesson is neither on this page nor in this filter, yet
        // its join button is still at the top.
        Livewire::withQueryParams(['status' => BookingStatus::Completed->value])
            ->actingAs($this->student)
            ->test(BookingHistory::class)
            ->assertSee('In progress')
            ->assertSeeHtml('data-next-up="'.$today->id.'"')
            ->assertSee('Join the lesson')
            ->assertSee(route('dashboard.meetings.join', $today), false)
            ->assertSee('No bookings match this status filter.')
            ->assertDontSee(self::JOIN_URL);
    }

    public function test_my_bookings_rows_show_an_inline_join_button_only_inside_the_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-13 19:05:00', 'UTC'));
        $today = $this->lessonAt(CarbonImmutable::parse('2026-09-13 19:00:00', 'UTC'));
        $tomorrow = $this->lessonAt(CarbonImmutable::parse('2026-09-14 19:00:00', 'UTC'));

        $page = Livewire::actingAs($this->student)->test(BookingHistory::class);

        // Pinned card + today's row: exactly two join buttons, both the gateway.
        $this->assertSame(2, substr_count($page->html(), 'Join the lesson'));
        $this->assertSame(2, substr_count($page->html(), route('dashboard.meetings.join', $today)));
        $page->assertDontSee(route('dashboard.meetings.join', $tomorrow), false)
            ->assertSeeHtml('class="relative z-10" data-join-state="available"');
    }

    // ── Upcoming Classes ────────────────────────────────────────────────────

    public function test_upcoming_classes_groups_today_and_later_and_uses_the_gateway_link(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-13 19:05:00', 'UTC'));
        $today = $this->dailySeries();

        Livewire::actingAs($this->student)
            ->test(UpcomingClasses::class)
            ->assertSeeHtml('data-schedule-group="today"')
            ->assertSeeHtml('data-schedule-group="later"')
            ->assertSee('Join the lesson')
            ->assertSee(route('dashboard.meetings.join', $today), false)
            ->assertSee('Join opens')
            ->assertSee('wire:poll.60s', false)
            ->assertDontSee(self::JOIN_URL)
            ->assertDontSee(self::LEGACY_URL)
            ->assertViewHas('classes', fn ($classes) => $classes->count() === 15 && $classes->first()->id === $today->id);
    }

    public function test_upcoming_classes_never_renders_the_legacy_meeting_url_without_a_meeting(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-13 19:05:00', 'UTC'));
        $this->lessonAt(CarbonImmutable::parse('2026-09-13 19:00:00', 'UTC'), withMeeting: false);

        Livewire::actingAs($this->student)
            ->test(UpcomingClasses::class)
            ->assertSee('The meeting link is being prepared.')
            ->assertDontSee('Join the lesson')
            ->assertDontSee(self::LEGACY_URL)
            ->assertDontSee('wire:poll', false);
    }

    public function test_today_follows_the_students_own_timezone(): void
    {
        // 20:00 UTC on 13 Sep is 01:30 on 14 Sep in Kolkata — not today there.
        $this->student->profile()->update(['timezone' => 'Asia/Kolkata']);
        $this->travelTo(CarbonImmutable::parse('2026-09-13 10:00:00', 'UTC'));
        $this->lessonAt(CarbonImmutable::parse('2026-09-13 20:00:00', 'UTC'));

        Livewire::actingAs($this->student->fresh())
            ->test(UpcomingClasses::class)
            ->assertSeeHtml('data-schedule-group="later"')
            ->assertDontSeeHtml('data-schedule-group="today"');
    }
}
