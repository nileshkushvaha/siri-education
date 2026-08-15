<?php

declare(strict_types=1);

namespace Tests\Feature\Display;

use App\Livewire\Frontend\Student\BookingHistory;
use App\Livewire\Frontend\Student\UpcomingClasses;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\GeneralSettings;
use App\Support\Timezone\ViewerDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TZ-4 — the logged-in viewer sees every instant in their own clock.
 *
 * The defining case is one stored UTC instant read by three different
 * people: the same row must render three different local times, and the
 * row itself must be unchanged afterwards. Before TZ-4 a student saw
 * their 09:00 lesson listed at 03:30 on their dashboard while the
 * confirmation email said 09:00 — list and email disagreeing about the
 * same booking.
 *
 * Rendering goes through the real Livewire components, not the
 * formatter alone: the bug was never in formatting, it was in which
 * timezone reached it.
 */
class ViewerTimezoneDisplayTest extends TestCase
{
    use RefreshDatabase;

    /** 23:30 UTC — late enough that the local calendar DATE differs east and west. */
    private const string INSTANT = '2026-08-15 23:30:00';

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    private function platformDefault(string $timezone): void
    {
        $settings = app(GeneralSettings::class);
        $settings->default_timezone = $timezone;
        $settings->save();
    }

    private function student(?string $timezone, ?string $countryTimezone = null): User
    {
        $student = User::factory()->activeStudent()->create([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $country = $countryTimezone === null
            ? null
            : Country::factory()->create(['default_timezone' => $countryTimezone]);

        UserProfile::updateOrCreate(['user_id' => $student->id], [
            'timezone' => $timezone,
            'country_id' => $country?->id,
        ]);

        return $student->fresh();
    }

    private function bookingFor(User $student): Booking
    {
        $startsAt = CarbonImmutable::parse(self::INSTANT, 'UTC');

        return Booking::factory()->create([
            'student_id' => $student->id,
            'booking_type_id' => BookingType::factory()->create(['key' => 'free_demo', 'name' => 'Free Demo'])->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            // The ORIGIN snapshot is deliberately a third timezone that
            // belongs to nobody viewing, so any surface still reading it
            // as the display timezone shows up immediately.
            'timezone' => 'Pacific/Auckland',
        ]);
    }

    // ── Cross-date boundary, on a real portal surface ───────────────────

    public function test_upcoming_classes_renders_the_viewers_local_date_not_the_utc_date(): void
    {
        $student = $this->student('Asia/Kolkata');
        $this->bookingFor($student);

        // 23:30 UTC on the 15th is 05:00 on the 16th in Kolkata.
        Livewire::actingAs($student)
            ->test(UpcomingClasses::class)
            ->assertSee('Sun, Aug 16')
            ->assertSee('5:00 AM')
            ->assertDontSee('11:30 PM');
    }

    public function test_the_same_booking_shows_the_previous_date_to_a_western_viewer(): void
    {
        $student = $this->student('America/Los_Angeles');
        $this->bookingFor($student);

        // …and 16:30 on the 15th in Los Angeles — one instant, two dates.
        Livewire::actingAs($student)
            ->test(UpcomingClasses::class)
            ->assertSee('Sat, Aug 15')
            ->assertSee('4:30 PM');
    }

    public function test_booking_history_list_and_detail_agree_with_each_other(): void
    {
        // The clearest symptom of TZ-AUD-004: the list row rendered UTC
        // while the detail panel beside it rendered a converted time, for
        // the same booking on the same screen.
        $student = $this->student('Asia/Kolkata');
        $this->bookingFor($student);

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->assertSee('Aug 16, 2026')
            ->assertSee('5:00 AM')
            ->assertDontSee('11:30 PM');
    }

    // ── Same instant, different viewers ─────────────────────────────────

    public function test_one_instant_renders_differently_for_each_viewer(): void
    {
        $instant = CarbonImmutable::parse(self::INSTANT, 'UTC');

        $studentView = ViewerDateTime::dateTime($instant, $this->student('Asia/Kolkata'));
        $instructorView = ViewerDateTime::dateTime($instant, $this->student('Europe/London'));
        $adminView = ViewerDateTime::dateTime($instant, $this->student('America/New_York'));

        $this->assertSame('Aug 16, 2026 5:00 AM', $studentView);
        $this->assertSame('Aug 16, 2026 12:30 AM', $instructorView);
        $this->assertSame('Aug 15, 2026 7:30 PM', $adminView);

        // Three renderings, one underlying instant.
        $this->assertCount(3, array_unique([$studentView, $instructorView, $adminView]));
    }

    public function test_display_follows_the_viewer_not_the_record_owner(): void
    {
        // An instructor reading a student's booking sees their OWN clock.
        // The booking's origin snapshot (Pacific/Auckland) must not win.
        $student = $this->student('Asia/Kolkata');
        $booking = $this->bookingFor($student);
        $instructor = $this->student('Europe/London');

        $this->assertSame('Aug 16, 2026 12:30 AM', ViewerDateTime::dateTime($booking->starts_at, $instructor));
        $this->assertNotSame(
            $booking->starts_at->setTimezone($booking->timezone)->format(ViewerDateTime::DATE_TIME),
            ViewerDateTime::dateTime($booking->starts_at, $instructor),
        );
    }

    // ── Resolution chain, exercised through display ─────────────────────

    public function test_a_multi_timezone_country_user_keeps_their_explicit_choice(): void
    {
        // Country default says New York; the user says Los Angeles. The
        // display sweep must not quietly reinstate Country as identity.
        $user = $this->student('America/Los_Angeles', 'America/New_York');

        $this->assertSame('America/Los_Angeles', ViewerDateTime::timezoneFor($user));
        $this->assertSame('Aug 15, 2026 4:30 PM', ViewerDateTime::dateTime(CarbonImmutable::parse(self::INSTANT, 'UTC'), $user));
    }

    public function test_an_invalid_stored_timezone_still_renders_a_page_using_the_country_fallback(): void
    {
        // A real page render, not a formatter call: the point is that a
        // legacy value cannot 500 a dashboard.
        $this->platformDefault('UTC');
        $student = $this->student('Invalid/Zone', 'Asia/Kolkata');
        $this->bookingFor($student);

        Livewire::actingAs($student)
            ->test(UpcomingClasses::class)
            ->assertOk()
            ->assertSee('Sun, Aug 16')
            ->assertSee('5:00 AM');
    }

    // ── DST ─────────────────────────────────────────────────────────────

    public function test_display_respects_the_dst_offset_in_force(): void
    {
        $viewer = $this->student('Europe/London');

        $this->assertSame(
            'Aug 15, 2026 1:00 PM',
            ViewerDateTime::dateTime(CarbonImmutable::parse('2026-08-15 12:00:00', 'UTC'), $viewer),
        );
        $this->assertSame(
            'Jan 15, 2027 12:00 PM',
            ViewerDateTime::dateTime(CarbonImmutable::parse('2027-01-15 12:00:00', 'UTC'), $viewer),
        );
    }

    // ── Rendering never mutates ─────────────────────────────────────────

    public function test_rendering_does_not_change_the_stored_instant(): void
    {
        $student = $this->student('Asia/Kolkata');
        $booking = $this->bookingFor($student);

        Livewire::actingAs($student)->test(UpcomingClasses::class)->assertOk();
        ViewerDateTime::dateTime($booking->starts_at, $student);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'starts_at' => self::INSTANT,
            'timezone' => 'Pacific/Auckland',
        ]);
        $this->assertSame(self::INSTANT, $booking->fresh()->starts_at->utc()->format('Y-m-d H:i:s'));
    }

    // ── Null handling ───────────────────────────────────────────────────

    public function test_null_instants_are_safe(): void
    {
        $this->assertNull(ViewerDateTime::local(null));
        $this->assertNull(ViewerDateTime::dateTime(null));
        $this->assertNull(ViewerDateTime::date(null));
        $this->assertNull(ViewerDateTime::labelled(null));
        $this->assertNull(viewer_datetime(null));
    }

    public function test_helpers_resolve_the_authenticated_viewer(): void
    {
        $student = $this->student('Asia/Kolkata');
        Auth::login($student);

        $this->assertSame('Aug 16, 2026 5:00 AM', viewer_datetime(CarbonImmutable::parse(self::INSTANT, 'UTC')));
        $this->assertSame('Aug 16, 2026', viewer_date(CarbonImmutable::parse(self::INSTANT, 'UTC')));
        $this->assertSame('5:00 AM', viewer_time(CarbonImmutable::parse(self::INSTANT, 'UTC')));
        $this->assertSame('Aug 16, 2026 5:00 AM (Asia/Kolkata)', viewer_datetime_labelled(CarbonImmutable::parse(self::INSTANT, 'UTC')));
    }

    public function test_an_already_localized_carbon_is_not_double_converted(): void
    {
        // Several services already hand viewer-local Carbons to their
        // views. Re-running them through the presenter must be a no-op,
        // because setTimezone preserves the absolute instant.
        $student = $this->student('Asia/Kolkata');
        $alreadyLocal = CarbonImmutable::parse(self::INSTANT, 'UTC')->setTimezone('Asia/Kolkata');

        $this->assertSame('Aug 16, 2026 5:00 AM', ViewerDateTime::dateTime($alreadyLocal, $student));
    }
}
