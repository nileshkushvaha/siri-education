<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\RecurrencePatternData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingHostReservationStatus;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Enums\RecurrenceEndCondition;
use App\Booking\Enums\RecurrenceFrequency;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\GatewayAmbiguousRequestException;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Exceptions\MeetingHostCapacityException;
use App\Booking\Meetings\GoogleCalendarMeetProvider;
use App\Booking\Meetings\ManualMeetingProvider;
use App\Booking\Meetings\ZoomMeetingProvider;
use App\Booking\Services\BookingMeetingService;
use App\Booking\Services\MeetingHostCapacityService;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\BookingSeriesException;
use App\Models\MeetingHostReservation;
use App\Models\PlatformMeetingHost;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackageEntitlementReservation;
use App\Models\User;
use App\Settings\BookingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsZoomHostCapacityFixtures;
use Tests\TestCase;

/**
 * One Zoom Pro host, one meeting at a time — and every path that can
 * commit a booking to it. Two instructors with empty calendars must
 * never both be sold the same hour on the one licence.
 *
 * The concurrency tests for the same rules (real processes, real MySQL
 * locks) live in Concurrency/MeetingHostCapacityConcurrencyTest; this
 * class covers the sequential contract: which commitments reserve, when
 * they release, what a reschedule does, how UTC boundaries behave, and
 * that Google Meet and the feature-off case are untouched.
 */
final class MeetingHostCapacityTest extends TestCase
{
    use BuildsZoomHostCapacityFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootZoomHostCapacityFixtures();
    }

    // ── Different teachers, one host ──────────────────────────────────

    public function test_two_teachers_cannot_both_book_the_single_host_for_the_same_hour(): void
    {
        $first = $this->demo($this->teacherA, $this->slot());

        $this->assertSame(BookingStatus::Confirmed, $first->status);
        $this->assertSame(ZoomMeetingProvider::KEY, $first->meeting_provider_intent);
        $this->assertNotNull($this->activeReservationFor($first));

        try {
            $this->demo($this->teacherB, $this->slot(), $this->makeStudent());
            $this->fail('The second teacher must not be sold the same hour on the one licence.');
        } catch (MeetingHostCapacityException $e) {
            $this->assertStringContainsString('This time is fully booked on our video platform', $e->getMessage());
            $this->assertStringNotContainsString('Zoom', $e->getMessage(), 'students never see the provider');
            $this->assertStringContainsString('No Zoom host is available', $e->detail());
        }

        $this->assertSame(1, Booking::query()->count(), 'the refused booking rolled back entirely');
        $this->assertSame(1, $this->activeReservations());
    }

    /** The occupied interval is wider than the lesson: early-join + late-join/closure + buffer on each side. */
    public function test_the_occupied_interval_adds_join_window_and_buffer_in_utc(): void
    {
        $booking = $this->demo($this->teacherA, $this->slot(3, 10, 0));
        $reservation = $this->activeReservationFor($booking);

        $this->assertNotNull($reservation);
        // 15 min early join + 5 min buffer before; 15 min late join + 5 min buffer after.
        $this->assertTrue($reservation->occupies_from->equalTo($booking->starts_at->utc()->subMinutes(20)));
        $this->assertTrue($reservation->occupies_until->equalTo($booking->ends_at->utc()->addMinutes(20)));
        $this->assertSame('UTC', $reservation->occupies_from->timezoneName);
    }

    /** Back-to-back on the boundary: the next lesson may start exactly when the previous occupied interval ends. */
    public function test_intervals_that_only_touch_do_not_collide_but_one_minute_inside_does(): void
    {
        // 10:00–10:30 lesson occupies 09:40–10:50.
        $this->demo($this->teacherA, $this->slot(3, 10, 0));

        // 11:10–11:40 occupies 10:50–12:00: touches 10:50, does not overlap.
        $touching = $this->demo($this->teacherB, $this->slot(3, 11, 10), $this->makeStudent());
        $this->assertSame(BookingStatus::Confirmed, $touching->status);

        // 12:19–12:49 occupies 11:59–13:09: one minute inside the previous interval.
        $this->expectException(MeetingHostCapacityException::class);
        $this->demo($this->makeTeacher(), $this->slot(3, 12, 19), $this->makeStudent());
    }

    /** A second licensed host doubles the pool; the reservation records which host it landed on. */
    public function test_a_second_registered_host_serves_the_second_teacher(): void
    {
        $second = $this->registerHost('second-zoom-host');

        $first = $this->demo($this->teacherA, $this->slot());
        $other = $this->demo($this->teacherB, $this->slot(), $this->makeStudent());

        $this->assertNotSame(
            $this->activeReservationFor($first)?->platform_meeting_host_id,
            $this->activeReservationFor($other)?->platform_meeting_host_id,
        );
        $this->assertSame($second->id, $this->activeReservationFor($other)?->platform_meeting_host_id);
    }

    // ── Commitment kinds ──────────────────────────────────────────────

    public function test_a_pending_payment_hold_reserves_capacity_with_the_hold_expiry(): void
    {
        $booking = $this->paid($this->teacherA, $this->slot());
        $reservation = $this->activeReservationFor($booking);

        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
        $this->assertNotNull($reservation);
        $this->assertTrue($reservation->expires_at->equalTo($booking->reserved_until));

        // While the hold stands, the hour is taken for everyone else.
        $this->expectException(MeetingHostCapacityException::class);
        $this->demo($this->teacherB, $this->slot(), $this->makeStudent());
    }

    public function test_an_expired_hold_releases_capacity_when_the_sweep_cancels_it(): void
    {
        $booking = $this->paid($this->teacherA, $this->slot());
        Booking::query()->whereKey($booking->id)->update(['reserved_until' => now()->subMinute()]);

        $this->artisan('booking:release-expired')->assertSuccessful();

        $released = MeetingHostReservation::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertSame(MeetingHostReservationStatus::Released, $released->status);
        $this->assertSame(MeetingHostCapacityService::RELEASE_HOLD_EXPIRED, $released->release_reason);

        // The hour is available again — to a different teacher.
        $next = $this->demo($this->teacherB, $this->slot(), $this->makeStudent());
        $this->assertSame(BookingStatus::Confirmed, $next->status);
    }

    public function test_payment_confirmation_keeps_the_reservation_and_clears_its_expiry(): void
    {
        $booking = $this->paid($this->teacherA, $this->slot());

        $booking = $this->settlePayment($booking);

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(1, MeetingHostReservation::query()->where('booking_id', $booking->id)->count(), 'confirmation reuses the hold, never a second row');
        $this->assertNull($this->activeReservationFor($booking)?->expires_at);
    }

    public function test_a_package_funded_booking_reserves_capacity_without_a_payment_hold(): void
    {
        $booking = $this->packageFunded($this->teacherA, $this->slot());

        $this->assertSame(BookingPaymentStatus::PackageFunded, $booking->payment_status);
        $this->assertNull($booking->reserved_until);
        $reservation = $this->activeReservationFor($booking);
        $this->assertNotNull($reservation);
        $this->assertNull($reservation->expires_at);
    }

    /** No capacity means no booking — and for a package, no unit consumed either. */
    public function test_a_refused_package_booking_consumes_no_entitlement_unit(): void
    {
        $this->demo($this->teacherA, $this->slot());

        try {
            $this->packageFunded($this->teacherB, $this->slot());
            $this->fail('Expected capacity refusal.');
        } catch (MeetingHostCapacityException) {
            // expected
        }

        $this->assertSame(0, StudentPackageEntitlement::query()->sole()->used_quantity);
        $this->assertSame(0, StudentPackageEntitlementReservation::query()->count());
    }

    // ── Recurring occurrences ─────────────────────────────────────────

    public function test_recurring_occurrences_that_find_the_host_taken_are_recorded_as_conflicts_not_dropped_silently(): void
    {
        $bookings = app(BookingSettings::class);
        $bookings->recurring_future_generation_enabled = true;
        $bookings->recurring_confirmation_horizon_days = 60;
        $bookings->save();

        // Teacher A holds the host next Monday and the Monday after at
        // 10:00 — with OTHER students, so the only thing standing in the
        // series student's way is the host.
        $monday = $this->nextWeekday(Weekday::Monday);
        $this->demo($this->teacherA, $monday, $this->makeStudent());
        $this->demo($this->teacherA, $monday->addWeek(), $this->makeStudent());

        // A three-week Monday 10:00 schedule with teacher B.
        $this->actingAs($this->student);
        $result = app(WizardBookingServiceInterface::class)->bookSeries(
            new WizardBookingData(typeKey: 'paid_one_to_one', subject: 'maths', grade: 7, startsAt: $monday, timezone: 'UTC', teacherId: $this->teacherB->id),
            new RecurrencePatternData(frequency: RecurrenceFrequency::Weekly, endCondition: RecurrenceEndCondition::AfterCount, occurrenceCount: 3),
        );

        $series = $result->series;
        $this->assertNotNull($series);

        $booked = $series->bookings()->pluck('series_occurrence_date')->map(fn ($d) => $d->toDateString())->all();
        $this->assertSame([$monday->addWeeks(2)->toDateString()], $booked, 'only the third Monday had host capacity');

        $conflicts = BookingSeriesException::query()->where('booking_series_id', $series->id)->where('action', BookingSeriesException::ACTION_CONFLICT)->get();
        $this->assertCount(2, $conflicts);
        $this->assertStringContainsString('This time is fully booked on our video platform', $conflicts->first()->reason);
    }

    private function nextWeekday(Weekday $weekday, int $hour = 10): CarbonImmutable
    {
        $date = CarbonImmutable::now('UTC')->addDays(3)->setTime($hour, 0);

        while ((int) $date->dayOfWeek !== $weekday->value) {
            $date = $date->addDay();
        }

        return $date;
    }

    // ── Reschedule ────────────────────────────────────────────────────

    public function test_a_successful_reschedule_releases_the_old_interval_and_reserves_the_new_one(): void
    {
        $booking = $this->demo($this->teacherA, $this->slot(3, 10));
        $original = $this->activeReservationFor($booking);

        app(BookingServiceInterface::class)->reschedule($booking, new RescheduleBookingData(startsAt: $this->slot(3, 14), actor: BookingActor::Admin));

        $rows = MeetingHostReservation::query()->where('booking_id', $booking->id)->get();
        $this->assertCount(2, $rows, 'history is kept: one released, one active');
        $released = $rows->firstWhere('status', MeetingHostReservationStatus::Released);
        $active = $rows->firstWhere('status', MeetingHostReservationStatus::Active);
        $this->assertNotNull($released);
        $this->assertNotNull($active);
        $this->assertSame(MeetingHostCapacityService::RELEASE_RESCHEDULED, $released->release_reason);
        $this->assertSame($original->id, $released->id);
        $this->assertTrue($active->occupies_from->equalTo($this->slot(3, 14)->subMinutes(20)));

        // 10:00 is free for another teacher again.
        $this->assertSame(BookingStatus::Confirmed, $this->demo($this->teacherB, $this->slot(3, 10), $this->makeStudent())->status);
    }

    public function test_a_rejected_reschedule_keeps_the_original_time_and_reservation_intact(): void
    {
        $mine = $this->demo($this->teacherA, $this->slot(3, 10));
        $theirs = $this->demo($this->teacherB, $this->slot(3, 14), $this->makeStudent());
        $original = $this->activeReservationFor($mine);

        try {
            app(BookingServiceInterface::class)->reschedule($mine, new RescheduleBookingData(startsAt: $this->slot(3, 14), actor: BookingActor::Admin));
            $this->fail('Moving onto the other teacher\'s hour must be refused.');
        } catch (MeetingHostCapacityException) {
            // expected
        }

        $mine->refresh();
        $this->assertTrue($mine->starts_at->equalTo($this->slot(3, 10)), 'time unchanged');
        $this->assertSame(1, MeetingHostReservation::query()->where('booking_id', $mine->id)->count(), 'no released row, no new row');
        $this->assertSame(MeetingHostReservationStatus::Active, $original->fresh()->status);
        $this->assertNotNull($this->activeReservationFor($theirs));
    }

    /** A booking may move onto the hour it currently holds (a shift of a few minutes) — its own reservation never blocks it. */
    public function test_a_booking_can_be_shifted_within_its_own_occupied_interval(): void
    {
        $booking = $this->demo($this->teacherA, $this->slot(3, 10, 0));

        $moved = app(BookingServiceInterface::class)->reschedule($booking, new RescheduleBookingData(startsAt: $this->slot(3, 10, 15), actor: BookingActor::Admin));

        $this->assertTrue($moved->starts_at->equalTo($this->slot(3, 10, 15)));
        $this->assertSame(1, $this->activeReservations());
    }

    /** Once a Zoom meeting exists the booking is pinned to that host, even when another host has room. */
    public function test_a_reschedule_keeps_the_meeting_on_its_existing_host(): void
    {
        $this->configureZoomDefault(capacityEnabled: true, autoCreate: true);
        $second = $this->registerHost('second-zoom-host');

        $booking = $this->demo($this->teacherA, $this->slot(3, 10));
        $meeting = BookingMeeting::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame(MeetingStatus::Created, $meeting->status);
        $firstHost = $meeting->platform_meeting_host_id;
        $this->assertNotNull($firstHost);
        $this->assertNotSame($second->id, $firstHost);

        // Another teacher fills the FIRST host at 14:00; the second host is free then.
        $blocker = $this->demo($this->teacherB, $this->slot(3, 14), $this->makeStudent());
        $this->assertSame($firstHost, $this->activeReservationFor($blocker)?->platform_meeting_host_id);

        try {
            app(BookingServiceInterface::class)->reschedule($booking, new RescheduleBookingData(startsAt: $this->slot(3, 14), actor: BookingActor::Admin));
            $this->fail('A created Zoom meeting cannot silently migrate to another host.');
        } catch (MeetingHostCapacityException $e) {
            $this->assertStringContainsString('already lives on', $e->getMessage());
        }

        // A time where its own host is free works, and stays on that host.
        app(BookingServiceInterface::class)->reschedule($booking->fresh(), new RescheduleBookingData(startsAt: $this->slot(3, 16), actor: BookingActor::Admin));
        $this->assertSame($firstHost, $this->activeReservationFor($booking)?->platform_meeting_host_id);
    }

    // ── Cancellation and duplicates ───────────────────────────────────

    public function test_cancellation_releases_the_host_and_a_duplicate_cancellation_event_changes_nothing(): void
    {
        $booking = $this->demo($this->teacherA, $this->slot());

        app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(cancelledBy: BookingActor::Admin, reason: 'test'));

        $row = MeetingHostReservation::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame(MeetingHostReservationStatus::Released, $row->status);
        $this->assertSame(MeetingHostCapacityService::RELEASE_CANCELLED, $row->release_reason);
        $releasedAt = $row->released_at;

        // A replayed release (the service is idempotent) touches nothing.
        DB::transaction(fn () => app(MeetingHostCapacityService::class)->release($booking->fresh(), 'duplicate'));
        $this->assertSame(1, MeetingHostReservation::query()->where('booking_id', $booking->id)->count());
        $this->assertTrue($row->fresh()->released_at->equalTo($releasedAt));
        $this->assertSame(MeetingHostCapacityService::RELEASE_CANCELLED, $row->fresh()->release_reason);

        $this->assertSame(BookingStatus::Confirmed, $this->demo($this->teacherB, $this->slot(), $this->makeStudent())->status);
    }

    // ── Provider pinning ──────────────────────────────────────────────

    public function test_changing_the_default_provider_after_acceptance_does_not_move_the_booking(): void
    {
        $this->configureZoomDefault(capacityEnabled: true, autoCreate: false, defaultProvider: ManualMeetingProvider::KEY);
        $booking = $this->demo($this->teacherA, $this->slot());

        $this->assertSame(ManualMeetingProvider::KEY, $booking->meeting_provider_intent);
        $this->assertSame(0, $this->activeReservations(), 'a manual-provider booking reserves no Zoom host');

        // Cutover happens after acceptance.
        $this->configureZoomDefault(capacityEnabled: true, autoCreate: true, defaultProvider: ZoomMeetingProvider::KEY);
        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking->fresh());

        $this->assertSame(ManualMeetingProvider::KEY, $meeting?->provider, 'the accepted provider wins over today\'s default');
        $this->assertSame([], $this->zoom->created, 'no Zoom host was consumed');
        $this->assertSame(0, $this->activeReservations());
    }

    /** A Zoom-accepted booking stays Zoom — on its reserved host — even if the default later moves back to Google. */
    public function test_a_zoom_accepted_booking_keeps_its_reservation_when_the_default_moves_away(): void
    {
        $booking = $this->demo($this->teacherA, $this->slot());
        $this->configureZoomDefault(capacityEnabled: true, autoCreate: true, defaultProvider: ManualMeetingProvider::KEY);

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking->fresh());

        $this->assertSame(ZoomMeetingProvider::KEY, $meeting?->provider);
        $this->assertSame(MeetingStatus::Created, $meeting?->status);
        $this->assertSame('platform-zoom-host', $this->zoom->created[0]['hostUser']);
        $this->assertSame($this->activeReservationFor($booking)?->platform_meeting_host_id, $meeting?->platform_meeting_host_id);
    }

    /** An admin who explicitly picks Zoom for an unreserved booking gets a clear failure when the host is taken — never a duplicate on the host. */
    public function test_creating_a_zoom_meeting_for_an_unreserved_booking_reserves_or_fails_clearly(): void
    {
        // A booking from before the feature: accepted for Google, no pin, no reservation.
        $this->configureZoomDefault(capacityEnabled: false, autoCreate: false, defaultProvider: GoogleCalendarMeetProvider::KEY);
        $legacy = $this->demo($this->teacherA, $this->slot());
        $this->assertNull($legacy->meeting_provider_intent);
        $this->assertSame(0, $this->activeReservations());

        $this->configureZoomDefault(capacityEnabled: true, autoCreate: false);
        $competitor = $this->demo($this->teacherB, $this->slot(), $this->makeStudent());
        $this->assertNotNull($this->activeReservationFor($competitor));

        // The admin's explicit choice runs through the same eligibility
        // gate as the automatic path, so the per-kind toggle must be on.
        $this->configureZoomDefault(capacityEnabled: true, autoCreate: true);
        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($legacy->fresh(), ZoomMeetingProvider::KEY);

        $this->assertSame(MeetingStatus::Failed, $meeting?->status);
        $this->assertStringContainsString('No Zoom host is available', (string) $meeting?->failure_reason);
        $this->assertSame([], $this->zoom->created, 'Zoom was never asked');
        $this->assertDatabaseHas('activity_log', ['event' => 'meeting_creation_failed']);
    }

    // ── Google Meet and feature-off are untouched ─────────────────────

    public function test_google_meet_bookings_reserve_nothing_and_are_pinned_to_google(): void
    {
        $this->configureZoomDefault(capacityEnabled: true, autoCreate: false, defaultProvider: GoogleCalendarMeetProvider::KEY);

        $a = $this->demo($this->teacherA, $this->slot());
        $b = $this->demo($this->teacherB, $this->slot(), $this->makeStudent());

        $this->assertSame(GoogleCalendarMeetProvider::KEY, $a->meeting_provider_intent);
        $this->assertSame(GoogleCalendarMeetProvider::KEY, $b->meeting_provider_intent);
        $this->assertSame(0, $this->activeReservations(), 'Google Meet has no host capacity constraint');
    }

    /** Google Meet default and the switch off — the pre-feature world — is untouched. */
    public function test_with_the_feature_off_and_google_default_nothing_changes(): void
    {
        $this->configureZoomDefault(capacityEnabled: false, autoCreate: false, defaultProvider: GoogleCalendarMeetProvider::KEY);

        $a = $this->demo($this->teacherA, $this->slot());
        $b = $this->demo($this->teacherB, $this->slot(), $this->makeStudent());

        $this->assertNull($a->meeting_provider_intent);
        $this->assertNull($b->meeting_provider_intent);
        $this->assertSame(0, MeetingHostReservation::query()->count());
    }

    public function test_the_feature_ships_off(): void
    {
        // setUp() switched it on for the other tests; the shipped default
        // is what the settings migration declares.
        $migration = (string) file_get_contents(base_path('database/settings/2026_11_24_100000_add_zoom_host_capacity_settings.php'));

        $this->assertStringContainsString("add('meeting.zoom_host_capacity_enabled', false)", $migration);
        $this->assertDatabaseHas('settings', ['group' => 'meeting', 'name' => 'zoom_host_capacity_enabled']);
    }

    // ── Ambiguous meeting creation ────────────────────────────────────

    /**
     * The create request timed out AFTER Zoom made the meeting. A blind
     * retry would create a second one; SIRI records the ambiguity,
     * reconciles, and adopts the meeting Zoom already holds.
     */
    public function test_an_ambiguous_create_is_recorded_and_reconciled_instead_of_duplicated(): void
    {
        $this->configureZoomDefault(capacityEnabled: true, autoCreate: false);
        $booking = $this->demo($this->teacherA, $this->slot());

        $this->configureZoomDefault(capacityEnabled: true, autoCreate: true); // eligibility gate for the explicit create
        $this->zoom->throwOnCreate = new GatewayAmbiguousRequestException('Zoom API request to create the meeting did not complete.');
        $this->zoom->createRemotelyOnAmbiguousFailure = true;

        $first = app(BookingMeetingServiceInterface::class)->createMeeting($booking->fresh(), ZoomMeetingProvider::KEY);

        $this->assertSame(MeetingStatus::Failed, $first?->status);
        $this->assertTrue($first?->metadata[BookingMeetingService::META_REMOTE_STATE_UNKNOWN] ?? false);
        $this->assertCount(1, $this->zoom->created, 'Zoom did create it before the connection dropped');

        // The retry reconciles first — and adopts, never creates again.
        $second = app(BookingMeetingServiceInterface::class)->createMeeting($booking->fresh(), ZoomMeetingProvider::KEY);

        $this->assertSame(MeetingStatus::Created, $second?->status);
        $this->assertSame(['createMeeting', 'findScheduledMeetings', 'updateMeeting'], $this->zoom->calls, 'query, then align-and-adopt; never a second create');
        $this->assertCount(1, $this->zoom->created, 'exactly one remote meeting, ever');
        $this->assertSame('900000000', $second?->provider_meeting_id, 'the meeting Zoom already held');
        $this->assertArrayNotHasKey(BookingMeetingService::META_REMOTE_STATE_UNKNOWN, $second?->metadata ?? []);
        $this->assertSame(1, BookingMeeting::query()->where('booking_id', $booking->id)->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'meeting_adopted_after_ambiguity']);
    }

    /**
     * Ambiguous, and the search finds nothing. That does NOT prove the
     * create failed (the listing is eventually consistent and bounded),
     * so SIRI fails closed: the row stays failed and flagged, nothing is
     * created, and repeated attempts only repeat the query. Only an
     * administrator's explicit declaration unlocks a fresh create.
     */
    public function test_a_no_match_reconciliation_fails_closed_until_an_administrator_declares_no_remote_meeting(): void
    {
        $booking = $this->demo($this->teacherA, $this->slot());
        $this->configureZoomDefault(capacityEnabled: true, autoCreate: true); // eligibility gate for the explicit create — after booking, so the listener does not pre-create

        $this->zoom->throwOnCreate = new GatewayAmbiguousRequestException('Zoom API request to create the meeting did not complete.');
        $this->zoom->createRemotelyOnAmbiguousFailure = false;

        app(BookingMeetingServiceInterface::class)->createMeeting($booking->fresh(), ZoomMeetingProvider::KEY);
        $this->assertCount(0, $this->zoom->created);

        // Repeated attempts: query, query, query — never a create.
        $second = app(BookingMeetingServiceInterface::class)->createMeeting($booking->fresh(), ZoomMeetingProvider::KEY);
        $third = app(BookingMeetingServiceInterface::class)->createMeeting($booking->fresh(), ZoomMeetingProvider::KEY);

        $this->assertSame(MeetingStatus::Failed, $second?->status);
        $this->assertSame(MeetingStatus::Failed, $third?->status);
        $this->assertTrue($third?->metadata[BookingMeetingService::META_REMOTE_STATE_UNKNOWN] ?? false, 'still unknown');
        $this->assertSame('none', $third?->metadata[BookingMeetingService::META_RECONCILIATION]['status'] ?? null);
        $this->assertStringContainsString('does not prove the original create failed', (string) $third?->failure_reason);
        $this->assertStringContainsString('meetings:resolve-ambiguous', (string) $third?->failure_reason);
        $this->assertSame(['createMeeting', 'findScheduledMeetings', 'findScheduledMeetings'], $this->zoom->calls);
        $this->assertCount(0, $this->zoom->created, 'no create after an inconclusive reconciliation');

        // Explicit resolution by a person who checked the Zoom account.
        $admin = $this->admin();
        app(BookingMeetingServiceInterface::class)->acknowledgeNoRemoteMeeting($booking->fresh(), $admin, 'Checked the Zoom account: nothing scheduled for this reference.');
        $this->assertDatabaseHas('activity_log', ['event' => 'meeting_ambiguity_acknowledged']);

        $fourth = app(BookingMeetingServiceInterface::class)->createMeeting($booking->fresh(), ZoomMeetingProvider::KEY);

        $this->assertSame(MeetingStatus::Created, $fourth?->status);
        $this->assertCount(1, $this->zoom->created, 'exactly one create, after the declaration');
    }

    /** Several remote meetings carry the reference: none may be adopted by guessing. */
    public function test_multiple_matches_are_recorded_and_never_adopted_automatically(): void
    {
        $booking = $this->demo($this->teacherA, $this->slot());
        $this->configureZoomDefault(capacityEnabled: true, autoCreate: true); // eligibility gate for the explicit create — after booking, so the listener does not pre-create

        // Two remote meetings for this booking (two unanswered creates, say).
        $this->zoom->throwOnCreate = new GatewayAmbiguousRequestException('did not complete');
        app(BookingMeetingServiceInterface::class)->createMeeting($booking->fresh(), ZoomMeetingProvider::KEY);
        $payload = $this->zoom->created[0]['payload'];
        $this->zoom->created[] = ['hostUser' => 'platform-zoom-host', 'payload' => $payload];
        $this->assertCount(2, $this->zoom->created);

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking->fresh(), ZoomMeetingProvider::KEY);

        $this->assertSame(MeetingStatus::Failed, $meeting?->status);
        $this->assertTrue($meeting?->metadata[BookingMeetingService::META_REMOTE_STATE_UNKNOWN] ?? false);
        $this->assertSame('inconclusive', $meeting?->metadata[BookingMeetingService::META_RECONCILIATION]['status'] ?? null);
        $this->assertSame(['900000000', '900000001'], $meeting?->metadata[BookingMeetingService::META_RECONCILIATION]['candidate_ids'] ?? []);
        $this->assertNotContains('updateMeeting', $this->zoom->calls, 'nothing adopted');
        $this->assertCount(2, $this->zoom->created, 'nothing created');

        // The operator identifies the right one; it is aligned and adopted.
        $adopted = app(BookingMeetingServiceInterface::class)->adoptRemoteMeeting($booking->fresh(), '900000001', $this->admin());

        $this->assertSame(MeetingStatus::Created, $adopted->status);
        $this->assertSame('900000001', $adopted->provider_meeting_id);
        $this->assertSame('900000001', $this->zoom->updated[0]['meetingId']);
        $this->assertDatabaseHas('activity_log', ['event' => 'meeting_adopted_by_admin']);
        $this->assertNotNull($this->activeReservationFor($booking), 'adoption reserves capacity like any Zoom meeting');
    }

    /** A search that could not cover every page proves nothing, even when it saw no match. */
    public function test_a_truncated_search_is_inconclusive(): void
    {
        $booking = $this->demo($this->teacherA, $this->slot());
        $this->configureZoomDefault(capacityEnabled: true, autoCreate: true); // eligibility gate for the explicit create — after booking, so the listener does not pre-create
        $this->zoom->throwOnCreate = new GatewayAmbiguousRequestException('did not complete');
        $this->zoom->createRemotelyOnAmbiguousFailure = false;
        $this->zoom->searchExhaustive = false;

        app(BookingMeetingServiceInterface::class)->createMeeting($booking->fresh(), ZoomMeetingProvider::KEY);
        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking->fresh(), ZoomMeetingProvider::KEY);

        $this->assertSame(MeetingStatus::Failed, $meeting?->status);
        $this->assertSame('inconclusive', $meeting?->metadata[BookingMeetingService::META_RECONCILIATION]['status'] ?? null);
        $this->assertFalse($meeting?->metadata[BookingMeetingService::META_RECONCILIATION]['exhaustive'] ?? true);
        $this->assertCount(0, $this->zoom->created);
    }

    /** A search failure (Zoom down) leaves the flag exactly as it was — still unknown. */
    public function test_a_failed_reconciliation_query_keeps_the_ambiguity(): void
    {
        $booking = $this->demo($this->teacherA, $this->slot());
        $this->configureZoomDefault(capacityEnabled: true, autoCreate: true); // eligibility gate for the explicit create — after booking, so the listener does not pre-create
        $this->zoom->throwOnCreate = new GatewayAmbiguousRequestException('did not complete');
        app(BookingMeetingServiceInterface::class)->createMeeting($booking->fresh(), ZoomMeetingProvider::KEY);

        $this->zoom->throwOnSearch = new GatewayRequestException('Zoom API failed to list host meetings (HTTP 503).');
        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking->fresh(), ZoomMeetingProvider::KEY);

        $this->assertSame(MeetingStatus::Failed, $meeting?->status);
        $this->assertTrue($meeting?->metadata[BookingMeetingService::META_REMOTE_STATE_UNKNOWN] ?? false);
        $this->assertCount(1, $this->zoom->created, 'still exactly the one Zoom made');
    }

    public function test_acknowledging_requires_a_pending_ambiguity(): void
    {
        $this->configureZoomDefault(capacityEnabled: true, autoCreate: true);
        $booking = $this->demo($this->teacherA, $this->slot());

        $this->expectException(BookingException::class);
        app(BookingMeetingServiceInterface::class)->acknowledgeNoRemoteMeeting($booking, $this->admin(), 'nothing to resolve');
    }

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    /** A definite provider error is not ambiguous and carries no remote-state flag. */
    public function test_a_definite_create_failure_is_not_flagged_as_ambiguous(): void
    {
        $this->configureZoomDefault(capacityEnabled: true, autoCreate: false);
        $booking = $this->demo($this->teacherA, $this->slot());
        $this->configureZoomDefault(capacityEnabled: true, autoCreate: true); // eligibility gate for the explicit create
        $this->zoom->throwOnCreate = new GatewayRequestException('Zoom API failed to create meeting (HTTP 400): Invalid host.');

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking->fresh(), ZoomMeetingProvider::KEY);

        $this->assertSame(MeetingStatus::Failed, $meeting?->status);
        $this->assertArrayNotHasKey(BookingMeetingService::META_REMOTE_STATE_UNKNOWN, $meeting?->metadata ?? []);
    }

    // ── Preflight ─────────────────────────────────────────────────────

    public function test_the_preflight_report_lists_unallocated_bookings_and_planned_overlaps_without_changing_anything(): void
    {
        // Accepted before the feature (for Google, no pin), later routed to
        // Zoom: no reservations exist for them.
        $this->configureZoomDefault(capacityEnabled: false, autoCreate: false, defaultProvider: GoogleCalendarMeetProvider::KEY);
        $a = $this->demo($this->teacherA, $this->slot(3, 10));
        $b = $this->demo($this->teacherB, $this->slot(3, 10), $this->makeStudent());
        Booking::query()->whereIn('id', [$a->id, $b->id])->update(['meeting_provider_intent' => ZoomMeetingProvider::KEY]);
        $this->configureZoomDefault(capacityEnabled: false, autoCreate: false);

        // Captured directly: the console test double matches each expected
        // substring against the first line containing it, and the overlap
        // line repeats the references, so substring expectations collide.
        $exitCode = Artisan::call('meetings:zoom-hosts:preflight');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, 'findings mean a non-zero exit');
        $this->assertStringContainsString('without a reservation', $output);
        $this->assertStringContainsString($a->reference, $output);
        $this->assertStringContainsString($b->reference, $output);
        $this->assertStringContainsString('2 concurrent (capacity 1)', $output);

        $this->assertSame(0, MeetingHostReservation::query()->count(), 'the report reserves nothing');
        $this->assertSame(BookingStatus::Confirmed, $a->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $b->fresh()->status);
    }

    public function test_the_preflight_report_passes_on_a_clean_pool(): void
    {
        $this->demo($this->teacherA, $this->slot());

        $this->artisan('meetings:zoom-hosts:preflight')
            ->expectsOutputToContain('No findings')
            ->assertSuccessful();
    }

    public function test_registering_the_host_is_idempotent(): void
    {
        $this->artisan('meetings:zoom-hosts:register', ['--host' => 'platform-zoom-host', '--capacity' => 1, '--label' => 'Renamed'])->assertSuccessful();

        $this->assertSame(1, PlatformMeetingHost::query()->count());
        $this->assertSame('Renamed', PlatformMeetingHost::query()->sole()->label);
    }
}
