<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\GoogleCalendarClient;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Events\BookingCancelled;
use App\Booking\Meetings\GoogleCalendarMeetProvider;
use App\Booking\Services\NotificationChannelResolver;
use App\Console\Commands\SyncPendingMeetings;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\User;
use App\Notifications\Booking\MeetingCreatedNotification;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The meeting lifecycle paths beyond first creation: cancellation when
 * the parent booking is cancelled, recovery of Google's asynchronous
 * conference creation (pending → created via the scheduled sweep,
 * pending → failed with a full audit trail), and the admin notification
 * paths for both failure directions. QUEUE_CONNECTION=sync in tests, so
 * every queued listener in these flows runs inline.
 */
class MeetingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function configureGoogle(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->meetings_enabled = true;
        $settings->google_meet_enabled = true;
        $settings->google_auth_type = 'service_account';
        $settings->google_calendar_id = 'primary';
        $settings->platform_meeting_account = 'meetings@sirieducation.com';
        $settings->google_credentials_json = Crypt::encryptString(
            json_encode(['type' => 'service_account', 'client_id' => '116902683368346528512', 'client_email' => 'svc@project.iam.gserviceaccount.com', 'private_key' => 'FAKE_PRIVATE_KEY_TOKEN_ABCDEFGHIJKLMNOP']),
        );
        $settings->save();
    }

    /** @param  list<string>  $allowedConferenceTypes */
    private function bindFakeClient(array $allowedConferenceTypes = ['hangoutsMeet']): GoogleCalendarClient&Mockery\MockInterface
    {
        $client = Mockery::mock(GoogleCalendarClient::class);
        $client->shouldReceive('allowedConferenceTypes')->andReturn($allowedConferenceTypes)->byDefault();
        $this->app->instance(GoogleCalendarClient::class, $client);

        return $client;
    }

    private function cancelBooking(Booking $booking): void
    {
        BookingCancelled::dispatch($booking, new CancelBookingData(cancelledBy: BookingActor::Admin));
    }

    // ── Cancellation ──────────────────────────────────────────────────

    public function test_cancelling_a_booking_deletes_the_provider_side_meeting_and_clears_legacy_columns(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $booking = Booking::factory()->confirmed()->create();
        BookingMeeting::factory()->google()->created('https://meet.google.com/live-link')->create([
            'booking_id' => $booking->id,
            'provider_event_id' => 'evt_live',
        ]);
        $booking->forceFill(['meeting_provider' => 'google_meet', 'meeting_url' => 'https://meet.google.com/live-link'])->save();

        $client->shouldReceive('deleteEvent')
            ->once()
            ->withArgs(fn(string $credentials, string $calendarId, string $eventId): bool => $eventId === 'evt_live');

        $this->cancelBooking($booking);

        $meeting = BookingMeeting::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(MeetingStatus::Cancelled, $meeting->status);
        $this->assertNull($booking->refresh()->meeting_url);
        $this->assertDatabaseHas('activity_log', ['event' => 'meeting_cancelled']);
    }

    public function test_cancelling_a_booking_without_a_meeting_is_a_noop(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $client->shouldNotReceive('deleteEvent');

        $booking = Booking::factory()->confirmed()->create();

        $this->cancelBooking($booking);

        $this->assertDatabaseMissing('booking_meetings', ['booking_id' => $booking->id]);
    }

    public function test_cancelling_a_meeting_with_no_provider_ids_marks_cancelled_without_touching_the_provider(): void
    {
        // Deliberately unconfigured: resolving the Google provider here
        // would throw — the short-circuit must never get that far when
        // there is nothing to delete provider-side.
        $client = $this->bindFakeClient();
        $client->shouldNotReceive('deleteEvent');

        $booking = Booking::factory()->confirmed()->create();
        BookingMeeting::factory()->google()->failed()->create([
            'booking_id' => $booking->id,
            'provider_event_id' => null,
            'provider_meeting_id' => null,
        ]);

        $this->cancelBooking($booking);

        $meeting = BookingMeeting::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(MeetingStatus::Cancelled, $meeting->status);
    }

    public function test_cancelling_an_already_cancelled_meeting_is_a_noop(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $client->shouldNotReceive('deleteEvent');

        $booking = Booking::factory()->confirmed()->create();
        BookingMeeting::factory()->google()->create([
            'booking_id' => $booking->id,
            'status' => MeetingStatus::Cancelled,
            'provider_event_id' => 'evt_gone',
        ]);

        $this->cancelBooking($booking);

        $this->assertSame(
            MeetingStatus::Cancelled,
            BookingMeeting::query()->where('booking_id', $booking->id)->firstOrFail()->status,
        );
    }

    public function test_failed_provider_side_cancellation_is_audited_for_admin_follow_up(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $booking = Booking::factory()->confirmed()->create();
        BookingMeeting::factory()->google()->created()->create([
            'booking_id' => $booking->id,
            'provider_event_id' => 'evt_stuck',
        ]);

        $client->shouldReceive('deleteEvent')->once()->andThrow(new \RuntimeException('Google Calendar API error (HTTP 500, reason: backendError): transient.'));

        $this->cancelBooking($booking);

        $meeting = BookingMeeting::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(MeetingStatus::Failed, $meeting->status);
        $this->assertNotNull($meeting->failure_reason);
        $this->assertDatabaseHas('activity_log', ['event' => 'meeting_cancellation_failed']);
    }

    // ── Async conference failure (result, not exception) ──────────────

    public function test_conference_creation_failure_result_is_audited_and_keeps_the_event_id_for_retry(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $booking = Booking::factory()->confirmed()->paid()->create();

        // The Calendar event inserted fine — only the async Meet
        // conference resolved to failure.
        $client->shouldReceive('insertEvent')->once()->andReturn([
            'id' => 'evt_conf_failed',
            'hangoutLink' => null,
            'conferenceData' => ['status' => 'failure', 'entryPoints' => []],
        ]);

        app(BookingMeetingServiceInterface::class)->createMeeting($booking, GoogleCalendarMeetProvider::KEY);

        $meeting = BookingMeeting::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(MeetingStatus::Failed, $meeting->status);
        $this->assertSame('evt_conf_failed', $meeting->provider_event_id);
        $this->assertNotNull($meeting->failure_reason);
        $this->assertDatabaseHas('activity_log', ['event' => 'meeting_creation_failed']);
    }

    // ── Pending-meeting sweep ──────────────────────────────────────────

    public function test_sync_pending_meetings_resolves_a_pending_google_conference_and_notifies_participants(): void
    {
        Notification::fake();
        $this->configureGoogle();
        app(MeetingSettings::class)->create_after_paid_booking_confirmation = true;
        app(MeetingSettings::class)->save();

        $client = $this->bindFakeClient();
        $booking = Booking::factory()->confirmed()->paid()->create();
        BookingMeeting::factory()->google()->create([
            'booking_id' => $booking->id,
            'status' => MeetingStatus::Pending,
            'provider_event_id' => 'evt_pending',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'updated_at' => now()->subMinutes(5),
        ]);

        $client->shouldReceive('updateEvent')
            ->once()
            ->withArgs(fn(string $credentials, string $calendarId, string $eventId): bool => $eventId === 'evt_pending')
            ->andReturn([
                'id' => 'evt_pending',
                'hangoutLink' => 'https://meet.google.com/now-ready',
                'conferenceData' => ['status' => 'success', 'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/now-ready']]],
            ]);

        $this->artisan(SyncPendingMeetings::class)
            ->expectsOutputToContain('1 resolved')
            ->assertSuccessful();

        $meeting = BookingMeeting::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(MeetingStatus::Created, $meeting->status);
        $this->assertSame('https://meet.google.com/now-ready', $meeting->join_url);

        Notification::assertSentTo($booking->instructor, MeetingCreatedNotification::class);
        Notification::assertSentTo($booking->student, MeetingCreatedNotification::class);
    }

    // ── In-app (database) notifications ────────────────────────────────

    public function test_meeting_created_notification_lands_in_the_database_and_shows_on_the_student_notifications_page(): void
    {
        // No Notification::fake() — this proves real end-to-end delivery
        // into the notifications table the dashboard page reads.
        $this->configureGoogle();
        app(MeetingSettings::class)->create_after_paid_booking_confirmation = true;
        app(MeetingSettings::class)->save();

        $client = $this->bindFakeClient();
        $booking = Booking::factory()->confirmed()->paid()->create();

        $client->shouldReceive('insertEvent')->once()->andReturn([
            'id' => 'evt_notify',
            'hangoutLink' => 'https://meet.google.com/in-app',
            'conferenceData' => ['status' => 'success', 'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/in-app']]],
        ]);

        app(BookingMeetingServiceInterface::class)
            ->createMeeting($booking, GoogleCalendarMeetProvider::KEY);

        $studentNotification = $booking->student->notifications()->firstOrFail();
        $this->assertSame('Meeting Created', $studentNotification->data['title']);
        $this->assertStringContainsString($booking->reference, $studentNotification->data['message']);
        $this->assertSame(1, $booking->instructor->notifications()->count());

        // And the student actually sees it on /dashboard/notifications.
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $booking->student->assignRole('student');
        $booking->student->update(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($booking->student)
            ->get(route('dashboard.notifications'))
            ->assertOk()
            ->assertSee('Meeting Created')
            ->assertSee($booking->reference);
    }

    public function test_guest_attendees_are_never_routed_to_the_database_channel(): void
    {
        $resolver = app(NotificationChannelResolver::class);

        $guest = Notification::route('mail', 'guest@sirieducation.com');
        $this->assertNotContains('database', $resolver->channels($guest));

        $user = User::factory()->create();
        $this->assertContains('database', $resolver->channels($user));
    }

    public function test_sync_pending_meetings_skips_past_meetings_and_non_confirmed_bookings(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $client->shouldNotReceive('updateEvent');
        $client->shouldNotReceive('insertEvent');

        // Already over: resolving a meeting after its window helps no one.
        $past = Booking::factory()->confirmed()->paid()->create();
        BookingMeeting::factory()->google()->create([
            'booking_id' => $past->id,
            'status' => MeetingStatus::Pending,
            'provider_event_id' => 'evt_past',
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHours(2),
            'updated_at' => now()->subHour(),
        ]);

        // Cancelled booking: its meeting is no longer this sweep's business.
        $cancelled = Booking::factory()->cancelled()->create();
        BookingMeeting::factory()->google()->create([
            'booking_id' => $cancelled->id,
            'status' => MeetingStatus::Pending,
            'provider_event_id' => 'evt_cancelled_booking',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->artisan(SyncPendingMeetings::class)
            ->expectsOutputToContain('Synced 0 pending meeting(s)')
            ->assertSuccessful();
    }

    public function test_sync_pending_meetings_skips_meetings_updated_moments_ago(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $client->shouldNotReceive('updateEvent');

        $booking = Booking::factory()->confirmed()->paid()->create();
        BookingMeeting::factory()->google()->create([
            'booking_id' => $booking->id,
            'status' => MeetingStatus::Pending,
            'provider_event_id' => 'evt_fresh',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'updated_at' => now(),
        ]);

        $this->artisan(SyncPendingMeetings::class)
            ->expectsOutputToContain('Synced 0 pending meeting(s)')
            ->assertSuccessful();
    }
}
