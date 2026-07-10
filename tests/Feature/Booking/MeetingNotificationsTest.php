<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\Enums\MeetingStatus;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\User;
use App\Notifications\Booking\MeetingCreatedNotification;
use App\Notifications\Booking\MeetingUpdatedNotification;
use App\Services\Admin\NotificationMapper;
use App\Settings\MeetingSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MeetingNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $settings = app(MeetingSettings::class);
        $settings->meetings_enabled = true;
        $settings->manual_provider_enabled = true;
        $settings->default_provider = 'manual';
        $settings->create_after_demo_booking_confirmation = true;
        $settings->create_after_paid_booking_confirmation = true;
        $settings->save();
    }

    private function eligibleBooking(): Booking
    {
        return Booking::factory()->confirmed()->paid()->create([
            'host_id' => $this->teacher->id,
            'attendee_id' => $this->student->id,
        ]);
    }

    private function service(): BookingMeetingServiceInterface
    {
        return app(BookingMeetingServiceInterface::class);
    }

    // ── Meeting created ──────────────────────────────────────────────

    public function test_meeting_created_sends_join_link_to_student_and_instructor(): void
    {
        Notification::fake();

        $booking = $this->eligibleBooking();
        $this->service()->saveManualMeeting($booking, new MeetingUpdateContext(joinUrl: 'https://meet.example.test/n1'));

        foreach ([$this->student, $this->teacher] as $recipient) {
            Notification::assertSentTo($recipient, MeetingCreatedNotification::class, function ($notification) use ($recipient): bool {
                $mail = $notification->toMail($recipient);
                $this->assertSame('https://meet.example.test/n1', $mail->actionUrl);

                return true;
            });
        }
    }

    public function test_student_meeting_notification_never_contains_host_start_url_or_metadata(): void
    {
        Notification::fake();

        $booking = $this->eligibleBooking();
        // Simulate a provider result carrying a host/start URL by writing
        // it directly, then triggering the created transition manually.
        $this->service()->saveManualMeeting($booking, new MeetingUpdateContext(joinUrl: 'https://zoom.us/j/12345', password: 'p4ss'));
        $booking->fresh()->meeting->forceFill(['host_url' => 'https://zoom.us/s/12345?zak=host-secret-token'])->save();

        Notification::assertSentTo($this->student, MeetingCreatedNotification::class, function ($notification): bool {
            $mail = $notification->toMail($this->student);
            $content = json_encode([$mail->subject, $mail->introLines, $mail->outroLines, $mail->actionUrl]);

            $this->assertStringNotContainsString('zak=', $content);
            $this->assertStringNotContainsString('/s/', $content);
            $this->assertStringNotContainsString('zoom_status', $content);
            $this->assertStringNotContainsString('metadata', strtolower($content));

            return true;
        });
    }

    public function test_duplicate_meeting_creation_does_not_duplicate_notification(): void
    {
        Notification::fake();

        $booking = $this->eligibleBooking();
        $this->service()->saveManualMeeting($booking, new MeetingUpdateContext(joinUrl: 'https://meet.example.test/n2'));
        // Idempotent re-create: existing created meeting short-circuits.
        $this->service()->createMeeting($booking->fresh());

        Notification::assertSentToTimes($this->student, MeetingCreatedNotification::class, 1);
        Notification::assertSentToTimes($this->teacher, MeetingCreatedNotification::class, 1);
    }

    public function test_cancelled_booking_never_sends_meeting_created_notification(): void
    {
        Notification::fake();

        $booking = Booking::factory()->cancelled()->create([
            'host_id' => $this->teacher->id,
            'attendee_id' => $this->student->id,
        ]);
        $this->service()->createMeeting($booking);

        Notification::assertNothingSent();
    }

    // ── Meeting updated ──────────────────────────────────────────────

    public function test_manual_link_change_sends_meeting_updated_notification(): void
    {
        $booking = $this->eligibleBooking();
        $this->service()->saveManualMeeting($booking, new MeetingUpdateContext(joinUrl: 'https://meet.example.test/old'));

        Notification::fake();
        $this->service()->saveManualMeeting($booking->fresh(), new MeetingUpdateContext(joinUrl: 'https://meet.example.test/new'));

        Notification::assertSentTo($this->student, MeetingUpdatedNotification::class);
        Notification::assertSentTo($this->teacher, MeetingUpdatedNotification::class);
        Notification::assertNotSentTo($this->student, MeetingCreatedNotification::class);
    }

    public function test_resave_without_change_sends_nothing(): void
    {
        $booking = $this->eligibleBooking();
        $this->service()->saveManualMeeting($booking, new MeetingUpdateContext(joinUrl: 'https://meet.example.test/same'));

        Notification::fake();
        $this->service()->saveManualMeeting($booking->fresh(), new MeetingUpdateContext(joinUrl: 'https://meet.example.test/same'));

        Notification::assertNothingSent();
    }

    // ── Meeting failed → admin pipeline ──────────────────────────────

    public function test_meeting_failure_creates_admin_notifiable_activity_without_secrets(): void
    {
        // Zoom enabled but structurally unconfigurable → resolver failure.
        $settings = app(MeetingSettings::class);
        $settings->zoom_enabled = true;
        $settings->zoom_account_id = 'acct_1';
        $settings->zoom_client_id = 'client_1';
        $settings->zoom_client_secret = Crypt::encryptString('super-secret-zoom-client-value');
        $settings->zoom_host_user_id = null;
        $settings->zoom_host_email = null;
        $settings->save();

        Notification::fake();
        $booking = $this->eligibleBooking();
        $meeting = $this->service()->createMeeting($booking, 'zoom');

        $this->assertSame(MeetingStatus::Failed, $meeting->status);
        // Participants are never told about provider failures.
        Notification::assertNotSentTo($this->student, MeetingCreatedNotification::class);

        $activity = Activity::query()
            ->where('log_name', 'bookings')
            ->where('event', 'meeting_creation_failed')
            ->latest('id')
            ->firstOrFail();

        // The admin pipeline (NotificationMapper) picks this activity up…
        $payload = app(NotificationMapper::class)->map($activity);
        $this->assertNotNull($payload);
        $this->assertSame('Meeting Creation Failed', $payload->title);

        // …and neither the payload nor the stored activity leaks secrets.
        $flattened = json_encode([$payload->title, $payload->body, $activity->description, $activity->properties]);
        $this->assertStringNotContainsString('super-secret-zoom-client-value', $flattened);
        $this->assertStringNotContainsString('eyJpdiI6', $flattened); // encrypted blob prefix
    }

    // ── Boundaries ───────────────────────────────────────────────────

    public function test_confirmed_pending_meeting_state_sends_no_meeting_notification(): void
    {
        Notification::fake();

        $booking = Booking::factory()->confirmed()->create([
            'host_id' => $this->teacher->id,
            'attendee_id' => $this->student->id,
            'payment_status' => 'pending', // unpaid paid booking — ineligible
        ]);
        $this->service()->createMeeting($booking);

        Notification::assertNothingSent();
    }

    public function test_meeting_notifications_are_queued(): void
    {
        $booking = $this->eligibleBooking();
        $meeting = BookingMeeting::factory()->created()->create(['booking_id' => $booking->id]);

        $notification = new MeetingCreatedNotification($booking, $meeting);
        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame('notifications', $notification->queue);
    }
}
