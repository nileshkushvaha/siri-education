<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\GoogleCalendarClient;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\RecurrencePatternData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingHostReservationStatus;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Enums\RecurrenceEndCondition;
use App\Booking\Enums\RecurrenceFrequency;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\MeetingHostCapacityException;
use App\Booking\Meetings\GoogleCalendarMeetProvider;
use App\Booking\Meetings\ZoomMeetingProvider;
use App\Booking\Repositories\MeetingHostReservationRepository;
use App\Booking\Services\MeetingHostCapacityService;
use App\Filament\Pages\Settings\MeetingSettingsPage;
use App\Models\Booking;
use App\Models\BookingSeriesException;
use App\Models\User;
use App\Settings\BookingSettings;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsZoomHostCapacityFixtures;
use Tests\TestCase;

/**
 * When every Zoom host is taken and the operator has chosen Google Meet
 * as the fallback, the booking is accepted on Meet instead of refused —
 * audited, surfaced to administrators (the platform Meet host must join
 * such a lesson), and only while Meet can actually create meetings.
 * With the setting off (the default) nothing changes but the wording.
 */
final class MeetingHostCapacityFallbackTest extends TestCase
{
    use BuildsZoomHostCapacityFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootZoomHostCapacityFixtures();
    }

    private function configureGoogle(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->google_meet_enabled = true;
        $settings->google_auth_type = 'service_account';
        $settings->google_calendar_id = 'primary';
        $settings->platform_meeting_account = 'meetings@example.com';
        $settings->google_credentials_json = Crypt::encryptString(
            json_encode(['type' => 'service_account', 'client_id' => '116902683368346528512', 'client_email' => 'svc@project.iam.gserviceaccount.com', 'private_key' => 'FAKE_PRIVATE_KEY_TOKEN_ABCDEFGHIJKLMNOP']),
        );
        $settings->save();
    }

    private function enableFallback(bool $configureGoogle = true): void
    {
        if ($configureGoogle) {
            $this->configureGoogle();
        }

        $settings = app(MeetingSettings::class);
        $settings->zoom_capacity_fallback_provider = GoogleCalendarMeetProvider::KEY;
        $settings->save();
    }

    public function test_the_setting_ships_off(): void
    {
        $this->assertNull(app(MeetingSettings::class)->zoom_capacity_fallback_provider);
        $this->assertStringContainsString(
            "add('meeting.zoom_capacity_fallback_provider', null)",
            (string) file_get_contents(base_path('database/settings/2026_11_26_100000_add_zoom_capacity_fallback_provider_setting.php')),
        );
    }

    public function test_with_the_fallback_off_a_full_hour_is_still_refused_without_naming_the_provider(): void
    {
        $this->demo($this->teacherA, $this->slot());

        try {
            $this->demo($this->teacherB, $this->slot(), $this->makeStudent());
            $this->fail('The second teacher must be refused while no fallback is configured.');
        } catch (MeetingHostCapacityException $e) {
            $this->assertSame('This time is fully booked on our video platform. Please choose another time.', $e->getMessage());
            $this->assertStringContainsString('No Zoom host is available', $e->detail());
            $this->assertTrue($e->mayFallBack);
        }

        $this->assertSame(1, Booking::query()->count());
    }

    public function test_with_the_fallback_on_a_full_hour_is_accepted_on_google_meet_and_the_host_is_alerted(): void
    {
        $this->enableFallback();
        $first = $this->demo($this->teacherA, $this->slot());

        $second = $this->demo($this->teacherB, $this->slot(), $this->makeStudent());

        $this->assertSame(BookingStatus::Confirmed, $second->status);
        $this->assertSame(ZoomMeetingProvider::KEY, $first->meeting_provider_intent);
        $this->assertSame(GoogleCalendarMeetProvider::KEY, $second->meeting_provider_intent);
        $this->assertNotNull($this->activeReservationFor($first));
        $this->assertNull($this->activeReservationFor($second), 'a Meet lesson holds no Zoom capacity');
        $this->assertSame(1, $this->activeReservations());

        $this->assertDatabaseHas('activity_log', ['event' => 'meeting_host_capacity_fallback', 'subject_id' => $second->id]);
        $this->assertDatabaseHas('booking_activities', ['booking_id' => $second->id, 'action' => 'requested']);
        $this->assertSame(GoogleCalendarMeetProvider::KEY, $second->activities()->where('action', 'requested')->firstOrFail()->meta['meeting_provider_fallback'] ?? null, 'the booking timeline records the switch');
        // The audit entry is what the admin notification is built from.
        $this->assertDatabaseHas('activity_log', ['event' => 'meeting_host_capacity_fallback', 'description' => sprintf('Zoom hosts were full for booking %s (%s to %s UTC); the lesson will run on Google Meet instead. The platform Meet host must join this lesson for it to start and record.', $second->reference, $this->slot()->subMinutes(20)->format('Y-m-d H:i'), $this->slot()->addMinutes(30)->addMinutes(20)->format('Y-m-d H:i'))]);
    }

    public function test_the_fallback_is_ignored_while_google_meet_is_not_configured(): void
    {
        $this->enableFallback(configureGoogle: false);
        $this->demo($this->teacherA, $this->slot());

        $this->expectException(MeetingHostCapacityException::class);
        $this->demo($this->teacherB, $this->slot(), $this->makeStudent());
    }

    public function test_the_operator_kill_switch_never_falls_back(): void
    {
        $this->enableFallback();
        $settings = app(MeetingSettings::class);
        $settings->zoom_host_capacity_enabled = false;
        $settings->save();

        try {
            $this->demo($this->teacherA, $this->slot());
            $this->fail('Reservation switched off must refuse, not substitute.');
        } catch (MeetingHostCapacityException $e) {
            $this->assertFalse($e->mayFallBack);
        }

        $this->assertSame(0, Booking::query()->count());
    }

    public function test_recurring_occurrences_that_find_the_host_taken_fall_back_individually(): void
    {
        $this->enableFallback();
        $bookings = app(BookingSettings::class);
        $bookings->recurring_future_generation_enabled = true;
        $bookings->recurring_confirmation_horizon_days = 60;
        $bookings->save();

        $monday = CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0);
        while ($monday->dayOfWeek !== (int) Weekday::Monday->value) {
            $monday = $monday->addDay();
        }
        $this->demo($this->teacherA, $monday, $this->makeStudent());
        $this->demo($this->teacherA, $monday->addWeek(), $this->makeStudent());

        $this->actingAs($this->student);
        $result = app(WizardBookingServiceInterface::class)->bookSeries(
            new WizardBookingData(typeKey: 'paid_one_to_one', subject: 'maths', grade: 7, startsAt: $monday, timezone: 'UTC', teacherId: $this->teacherB->id),
            new RecurrencePatternData(frequency: RecurrenceFrequency::Weekly, endCondition: RecurrenceEndCondition::AfterCount, occurrenceCount: 3),
        );

        $series = $result->series;
        $this->assertNotNull($series);
        $this->assertSame(3, $series->bookings()->count(), 'every Monday was accepted');
        $this->assertSame(0, BookingSeriesException::query()->where('booking_series_id', $series->id)->where('action', BookingSeriesException::ACTION_CONFLICT)->count());
        $this->assertSame(2, $series->bookings()->where('meeting_provider_intent', GoogleCalendarMeetProvider::KEY)->count(), 'the two taken Mondays run on Meet');
        $this->assertSame(1, $series->bookings()->where('meeting_provider_intent', ZoomMeetingProvider::KEY)->count());
    }

    public function test_a_reschedule_onto_a_full_hour_moves_the_lesson_to_google_meet_when_no_zoom_meeting_exists(): void
    {
        $this->enableFallback();
        $mine = $this->demo($this->teacherA, $this->slot(3, 10));
        $this->demo($this->teacherB, $this->slot(3, 14), $this->makeStudent());
        $original = $this->activeReservationFor($mine);

        $moved = app(BookingServiceInterface::class)->reschedule($mine, new RescheduleBookingData(startsAt: $this->slot(3, 14), actor: BookingActor::Admin));

        $this->assertTrue($moved->starts_at->equalTo($this->slot(3, 14)));
        $this->assertSame(GoogleCalendarMeetProvider::KEY, $moved->meeting_provider_intent);
        $this->assertSame(MeetingHostReservationStatus::Released, $original->fresh()->status);
        $this->assertSame(MeetingHostCapacityService::RELEASE_RESCHEDULED, $original->fresh()->release_reason);
        $this->assertNull($this->activeReservationFor($moved));
        $this->assertDatabaseHas('activity_log', ['event' => 'meeting_host_capacity_fallback', 'subject_id' => $moved->id]);

        // The 10:00 Zoom hour is free for someone else again.
        $this->assertSame(BookingStatus::Confirmed, $this->demo($this->teacherB, $this->slot(3, 10), $this->makeStudent())->status);
    }

    public function test_a_reschedule_of_a_created_zoom_meeting_onto_a_full_hour_is_still_refused(): void
    {
        $this->enableFallback();
        $this->configureZoomDefault(capacityEnabled: true, autoCreate: true);
        $mine = $this->demo($this->teacherA, $this->slot(3, 10));
        $this->assertSame(MeetingStatus::Created, $mine->fresh()->meeting?->status);
        $this->demo($this->teacherB, $this->slot(3, 14), $this->makeStudent());

        try {
            app(BookingServiceInterface::class)->reschedule($mine, new RescheduleBookingData(startsAt: $this->slot(3, 14), actor: BookingActor::Admin));
            $this->fail('A lesson whose Zoom meeting exists keeps its link and is refused.');
        } catch (MeetingHostCapacityException $e) {
            $this->assertFalse($e->mayFallBack);
        }

        $this->assertSame(ZoomMeetingProvider::KEY, $mine->fresh()->meeting_provider_intent);
        $this->assertTrue($mine->fresh()->starts_at->equalTo($this->slot(3, 10)));
    }

    public function test_a_confirmation_that_finds_no_capacity_moves_the_lesson_to_google_meet(): void
    {
        $this->enableFallback();
        $hold = $this->paid($this->teacherA, $this->slot());
        $reservation = $this->activeReservationFor($hold);
        $this->assertNotNull($reservation);

        // Simulate a hold accepted before the feature: give the capacity
        // back, let another teacher take the hour, then settle the payment.
        app(MeetingHostReservationRepository::class)->release($reservation, MeetingHostCapacityService::RELEASE_HOLD_EXPIRED);
        $this->demo($this->teacherB, $this->slot(), $this->makeStudent());

        $confirmed = $this->settlePayment($hold->fresh());

        $this->assertSame(BookingStatus::Confirmed, $confirmed->status);
        $this->assertSame(GoogleCalendarMeetProvider::KEY, $confirmed->meeting_provider_intent);
        $this->assertDatabaseHas('activity_log', ['event' => 'meeting_host_capacity_fallback', 'subject_id' => $confirmed->id]);
        $this->assertDatabaseMissing('activity_log', ['event' => 'meeting_host_capacity_unreserved', 'subject_id' => $confirmed->id]);
    }

    public function test_automatic_meeting_creation_without_capacity_switches_to_google_meet_instead_of_failing_on_zoom(): void
    {
        $this->enableFallback();
        // Google's client is a bare mock: the Meet create itself is not
        // under test here, only that the lesson left Zoom for Meet.
        $client = Mockery::mock(GoogleCalendarClient::class);
        $client->shouldReceive('allowedConferenceTypes')->andReturn(['hangoutsMeet'])->byDefault();
        $this->app->instance(GoogleCalendarClient::class, $client);

        $legacy = $this->demo($this->teacherA, $this->slot());
        $reservation = $this->activeReservationFor($legacy);
        app(MeetingHostReservationRepository::class)->release($reservation, MeetingHostCapacityService::RELEASE_HOLD_EXPIRED);
        $this->demo($this->teacherB, $this->slot(), $this->makeStudent());

        $this->configureZoomDefault(capacityEnabled: true, autoCreate: true);
        $this->enableFallback();
        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($legacy->fresh());

        $this->assertSame(GoogleCalendarMeetProvider::KEY, $legacy->fresh()->meeting_provider_intent);
        $this->assertSame(GoogleCalendarMeetProvider::KEY, $meeting?->provider, 'the meeting row belongs to Meet, whatever Google answered');
        $this->assertSame([], $this->zoom->created, 'Zoom was never asked');
        $this->assertDatabaseHas('activity_log', ['event' => 'meeting_host_capacity_fallback', 'subject_id' => $legacy->id]);
    }

    public function test_the_settings_page_refuses_google_meet_as_fallback_until_meet_is_configured(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        Livewire::test(MeetingSettingsPage::class)
            ->set('data.zoom_capacity_fallback_provider', GoogleCalendarMeetProvider::KEY)
            ->call('save')
            ->assertNotified('Meeting settings not saved');

        $this->assertNull(app(MeetingSettings::class)->refresh()->zoom_capacity_fallback_provider);

        $this->configureGoogle();

        Livewire::test(MeetingSettingsPage::class)
            ->set('data.zoom_capacity_fallback_provider', GoogleCalendarMeetProvider::KEY)
            ->call('save')
            ->assertNotNotified('Meeting settings not saved');

        $this->assertSame(GoogleCalendarMeetProvider::KEY, app(MeetingSettings::class)->refresh()->zoom_capacity_fallback_provider);
    }
}
