<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\MeetingAttendanceSyncServiceInterface;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingAttendanceProcessingStatus;
use App\Booking\Meetings\FakeMeetingProvider;
use App\Lessons\Contracts\LessonFinalizationServiceInterface;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\Lesson;
use App\Models\LessonAttendanceEvent;
use App\Models\LessonAttendanceRecord;
use App\Models\MeetingAttendanceProviderEvent;
use App\Settings\LessonSettings;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 17C — provider attendance ingestion: signed webhooks,
 * normalization, participant resolution, idempotency, sync
 * reconciliation, failure isolation, and privacy guarantees.
 */
class MeetingAttendanceIngestionTest extends TestCase
{
    use RefreshDatabase;

    private const string STUDENT_REF = 'prov-participant-student-1';

    private const string INSTRUCTOR_REF = 'prov-participant-instructor-1';

    private LessonLifecycleServiceInterface $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        FakeMeetingProvider::reset();
        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
    }

    // ── 1–2. Signed webhook happy paths ──────────────────────────────

    public function test_valid_signed_webhook_records_student_attendance(): void
    {
        $this->enableWebhooks();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        $response = $this->postWebhook($this->envelope($meeting, [
            $this->sessionEvent('evt-s1', self::STUDENT_REF, $lesson, 0, 40),
        ]));

        $response->assertStatus(202)->assertJson(['status' => 'queued']);

        $record = LessonAttendanceRecord::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(1, $record->student_join_count);
        $this->assertSame(40 * 60, $record->student_attended_seconds);
        $this->assertSame(0, $record->instructor_join_count);

        $row = MeetingAttendanceProviderEvent::query()->firstOrFail();
        $this->assertSame(MeetingAttendanceProcessingStatus::Processed, $row->processing_status);
        $this->assertSame($meeting->id, $row->booking_meeting_id);
        $this->assertSame($lesson->id, $row->lesson_id);
    }

    public function test_valid_signed_webhook_records_instructor_attendance(): void
    {
        $this->enableWebhooks();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        $this->postWebhook($this->envelope($meeting, [
            $this->sessionEvent('evt-i1', self::INSTRUCTOR_REF, $lesson, 0, 55, role: 'instructor'),
        ]))->assertStatus(202);

        $record = LessonAttendanceRecord::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(1, $record->instructor_join_count);
        $this->assertSame(55 * 60, $record->instructor_attended_seconds);
    }

    // ── 3–5. Rejections ──────────────────────────────────────────────

    public function test_invalid_signature_is_rejected(): void
    {
        $this->enableWebhooks();
        [, $meeting] = $this->makeLessonWithMeeting();

        $payload = $this->envelope($meeting, []);

        $this->postJson($this->webhookUri(), $payload, ['X-Fake-Signature' => 'forged'])
            ->assertStatus(401);

        $this->assertSame(0, MeetingAttendanceProviderEvent::query()->count());
        $this->assertSame(0, LessonAttendanceRecord::query()->count());
    }

    public function test_duplicate_provider_event_is_idempotent(): void
    {
        $this->enableWebhooks();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        $payload = $this->envelope($meeting, [
            $this->sessionEvent('evt-dup', self::STUDENT_REF, $lesson, 0, 30),
        ]);

        $this->postWebhook($payload)->assertStatus(202);
        $this->postWebhook($payload)->assertStatus(200)->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, MeetingAttendanceProviderEvent::query()->count());

        $record = LessonAttendanceRecord::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(1, $record->student_join_count);
        $this->assertSame(30 * 60, $record->student_attended_seconds);
    }

    public function test_unknown_provider_is_rejected(): void
    {
        $this->enableWebhooks();

        $this->postJson('/api/webhooks/meetings/attendance/nonexistent', ['event_id' => 'x'])
            ->assertStatus(404);
    }

    public function test_disabled_webhooks_reject_all_providers(): void
    {
        $this->postJson($this->webhookUri(), ['event_id' => 'x'])->assertStatus(404);
    }

    public function test_malformed_payload_is_rejected_without_internal_details(): void
    {
        $this->enableWebhooks();
        $this->makeLessonWithMeeting();

        $payload = ['event_id' => 'evt-bad', 'meeting_ref' => 'mtg-1', 'events' => [
            ['id' => 'e1', 'participant_ref' => 'x', 'type' => 'teleported', 'occurred_at' => 'now'],
        ]];

        $this->postWebhook($payload)
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Malformed webhook payload.']);

        $this->assertSame(0, MeetingAttendanceProviderEvent::query()->count());
    }

    // ── 6–8. Meeting / participant safety ────────────────────────────

    public function test_unknown_meeting_creates_no_attendance(): void
    {
        $this->enableWebhooks();
        [$lesson] = $this->makeLessonWithMeeting();

        $payload = [
            'event_id' => 'evt-unknown-mtg',
            'meeting_ref' => 'mtg-does-not-exist',
            'events' => [$this->sessionEvent('evt-x', self::STUDENT_REF, $lesson, 0, 30)],
        ];

        $this->postWebhook($payload)->assertStatus(202);

        $row = MeetingAttendanceProviderEvent::query()->firstOrFail();
        $this->assertSame(MeetingAttendanceProcessingStatus::Review, $row->processing_status);
        $this->assertSame('unknown_meeting', $row->status_reason);
        $this->assertSame(0, LessonAttendanceRecord::query()->count());
    }

    public function test_unauthorized_participant_creates_no_attendance(): void
    {
        $this->enableWebhooks();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        $this->postWebhook($this->envelope($meeting, [
            $this->sessionEvent('evt-stranger', 'someone-else-entirely', $lesson, 0, 30),
        ]))->assertStatus(202);

        $row = MeetingAttendanceProviderEvent::query()->firstOrFail();
        $this->assertSame(MeetingAttendanceProcessingStatus::Review, $row->processing_status);
        $this->assertSame('unknown_participant', $row->status_reason);
        $this->assertSame(0, LessonAttendanceRecord::query()->count());
    }

    public function test_participant_role_cannot_be_spoofed(): void
    {
        $this->enableWebhooks();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        // The stored map says this reference is the STUDENT; the webhook
        // claims instructor. Stored data wins: the event goes to review
        // and no attendance is recorded for either party.
        $this->postWebhook($this->envelope($meeting, [
            $this->sessionEvent('evt-spoof', self::STUDENT_REF, $lesson, 0, 55, role: 'instructor'),
        ]))->assertStatus(202);

        $row = MeetingAttendanceProviderEvent::query()->firstOrFail();
        $this->assertSame(MeetingAttendanceProcessingStatus::Review, $row->processing_status);
        $this->assertSame(0, LessonAttendanceRecord::query()->count());
    }

    // ── 9–13. Normalization semantics ────────────────────────────────

    public function test_join_and_leave_events_produce_the_correct_interval(): void
    {
        $this->enableWebhooks();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        $this->postWebhook($this->envelope($meeting, [
            $this->sessionEvent('evt-interval', self::STUDENT_REF, $lesson, 5, 42),
        ]))->assertStatus(202);

        $record = LessonAttendanceRecord::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(42 * 60, $record->student_attended_seconds);
        $this->assertTrue($record->student_first_joined_at->equalTo($lesson->starts_at->addMinutes(5)));
        $this->assertTrue($record->student_last_left_at->equalTo($lesson->starts_at->addMinutes(47)));
    }

    public function test_out_of_order_events_reconcile_correctly(): void
    {
        $this->enableWebhooks();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        // The rejoin session's webhook arrives before the first session's.
        $this->postWebhook($this->envelope($meeting, [
            $this->sessionEvent('evt-2', self::STUDENT_REF, $lesson, 40, 15),
        ], envelopeId: 'env-2'))->assertStatus(202);
        $this->postWebhook($this->envelope($meeting, [
            $this->sessionEvent('evt-1', self::STUDENT_REF, $lesson, 0, 30),
        ], envelopeId: 'env-1'))->assertStatus(202);

        $record = LessonAttendanceRecord::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(2, $record->student_join_count);
        $this->assertSame((30 + 15) * 60, $record->student_attended_seconds);
        $this->assertTrue($record->student_first_joined_at->equalTo($lesson->starts_at));
    }

    public function test_webhook_and_sync_overlap_without_double_counting(): void
    {
        $this->enableWebhooks();
        $this->enableSync();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        $this->postWebhook($this->envelope($meeting, [
            $this->sessionEvent('evt-w1', self::STUDENT_REF, $lesson, 0, 30),
        ]))->assertStatus(202);

        // The sync pull reports an overlapping 15–45 session.
        FakeMeetingProvider::$syncEvents = [
            FakeMeetingProvider::makeEvent(
                'sync-s1', $meeting->provider_meeting_id, self::STUDENT_REF, 'session',
                occurredAt: $lesson->ends_at->toIso8601String(),
                joinedAt: $lesson->starts_at->addMinutes(15)->toIso8601String(),
                leftAt: $lesson->starts_at->addMinutes(45)->toIso8601String(),
            ),
        ];

        $this->artisan('meetings:sync-attendance')->assertSuccessful();

        $record = LessonAttendanceRecord::query()->where('lesson_id', $lesson->id)->firstOrFail();
        // 0–30 ∪ 15–45 = 45 minutes, never 60.
        $this->assertSame(45 * 60, $record->student_attended_seconds);
    }

    public function test_duration_only_provider_evidence_is_normalized_safely(): void
    {
        $this->enableWebhooks();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        $this->postWebhook($this->envelope($meeting, [[
            'id' => 'evt-duration',
            'participant_ref' => self::STUDENT_REF,
            'type' => 'duration_only',
            'occurred_at' => $lesson->ends_at->toIso8601String(),
            'seconds' => 1800,
        ]]))->assertStatus(202);

        $record = LessonAttendanceRecord::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(1800, $record->student_attended_seconds);
        $this->assertSame(0, $record->student_join_count);
    }

    public function test_missing_leave_event_does_not_invent_attendance_duration(): void
    {
        $this->enableWebhooks();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        $this->postWebhook($this->envelope($meeting, [[
            'id' => 'evt-join-only',
            'participant_ref' => self::STUDENT_REF,
            'type' => 'joined',
            'occurred_at' => $lesson->starts_at->toIso8601String(),
            'joined_at' => $lesson->starts_at->toIso8601String(),
        ]]))->assertStatus(202);

        $record = LessonAttendanceRecord::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(1, $record->student_join_count);
        $this->assertSame(0, $record->student_attended_seconds);
    }

    // ── 14–15. Late / cancelled ──────────────────────────────────────

    public function test_late_evidence_is_stored_and_flagged(): void
    {
        $this->enableWebhooks();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        app(LessonOutcomeServiceInterface::class)->finalize($lesson);
        $this->assertSame(LessonOutcome::BothAbsent, $lesson->refresh()->outcome);

        $this->postWebhook($this->envelope($meeting, [
            $this->sessionEvent('evt-late', self::STUDENT_REF, $lesson, 0, 30),
        ]))->assertStatus(202);

        $row = MeetingAttendanceProviderEvent::query()->firstOrFail();
        $this->assertSame(MeetingAttendanceProcessingStatus::Processed, $row->processing_status);
        $this->assertSame('late', $row->results[0]['outcome']);

        $record = LessonAttendanceRecord::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertNotNull($record->late_evidence_reported_at);
        $this->assertSame(0, $record->student_join_count);
        // The finalized outcome is never silently rewritten.
        $this->assertSame(LessonOutcome::BothAbsent, $lesson->refresh()->outcome);
    }

    public function test_cancelled_lesson_rejects_normal_evidence_ingestion(): void
    {
        $this->enableWebhooks();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();
        $lesson->booking->update(['status' => BookingStatus::Cancelled]);

        $this->postWebhook($this->envelope($meeting, [
            $this->sessionEvent('evt-cancelled', self::STUDENT_REF, $lesson, 0, 30),
        ]))->assertStatus(202);

        $row = MeetingAttendanceProviderEvent::query()->firstOrFail();
        $this->assertSame(MeetingAttendanceProcessingStatus::Ignored, $row->processing_status);
        $this->assertSame('booking_cancelled', $row->status_reason);
        $this->assertSame(0, LessonAttendanceRecord::query()->count());
    }

    // ── 16–19. Sync command behaviour ────────────────────────────────

    public function test_sync_command_is_idempotent(): void
    {
        $this->enableSync();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        FakeMeetingProvider::$syncEvents = [
            FakeMeetingProvider::makeEvent(
                'sync-1', $meeting->provider_meeting_id, self::STUDENT_REF, 'session',
                occurredAt: $lesson->ends_at->toIso8601String(),
                joinedAt: $lesson->starts_at->toIso8601String(),
                leftAt: $lesson->ends_at->toIso8601String(),
            ),
        ];

        $this->artisan('meetings:sync-attendance')
            ->expectsOutputToContain('Settled attendance for 1 meeting(s).')->assertSuccessful();
        $this->artisan('meetings:sync-attendance')
            ->expectsOutputToContain('Settled attendance for 0 meeting(s).')->assertSuccessful();

        $meeting->refresh();
        $this->assertNotNull($meeting->attendance_synced_at);
        $this->assertSame('synced', $meeting->attendance_sync_status);

        $record = LessonAttendanceRecord::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(60 * 60, $record->student_attended_seconds);
        $this->assertSame(1, $record->student_join_count);
    }

    public function test_one_provider_failure_does_not_stop_the_batch(): void
    {
        $this->enableSync();
        [, $failingMeeting] = $this->makeLessonWithMeeting('mtg-fail');
        [$lesson2, $meeting2] = $this->makeLessonWithMeeting('mtg-ok');

        FakeMeetingProvider::$failNextSync = true; // first meeting only — the flag self-clears
        FakeMeetingProvider::$syncEvents = [
            FakeMeetingProvider::makeEvent(
                'sync-ok-1', $meeting2->provider_meeting_id, self::STUDENT_REF, 'session',
                occurredAt: $lesson2->ends_at->toIso8601String(),
                joinedAt: $lesson2->starts_at->toIso8601String(),
                leftAt: $lesson2->ends_at->toIso8601String(),
            ),
        ];

        $this->artisan('meetings:sync-attendance')->assertSuccessful();

        $this->assertSame('failed_transient', $failingMeeting->refresh()->attendance_sync_status);
        $this->assertSame('synced', $meeting2->refresh()->attendance_sync_status);
        $this->assertSame(1, LessonAttendanceRecord::query()->where('lesson_id', $lesson2->id)->count());
    }

    public function test_unsupported_providers_are_skipped(): void
    {
        $this->enableSync();
        [, $meeting] = $this->makeLessonWithMeeting();

        FakeMeetingProvider::$supportsSync = false;

        $this->artisan('meetings:sync-attendance')
            ->expectsOutputToContain('Settled attendance for 0 meeting(s).')->assertSuccessful();

        $this->assertSame('unsupported', $meeting->refresh()->attendance_sync_status);
        $this->assertSame(0, MeetingAttendanceProviderEvent::query()->count());
    }

    public function test_retryable_failures_remain_eligible_for_retry(): void
    {
        $this->enableSync();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        FakeMeetingProvider::$failNextSync = true;
        app(MeetingAttendanceSyncServiceInterface::class)->sync();

        $meeting->refresh();
        $this->assertSame('failed_transient', $meeting->attendance_sync_status);
        $this->assertNull($meeting->attendance_synced_at);

        // Provider recovered — the next run settles the meeting.
        FakeMeetingProvider::$syncEvents = [
            FakeMeetingProvider::makeEvent(
                'sync-retry-1', $meeting->provider_meeting_id, self::STUDENT_REF, 'session',
                occurredAt: $lesson->ends_at->toIso8601String(),
                joinedAt: $lesson->starts_at->toIso8601String(),
                leftAt: $lesson->ends_at->toIso8601String(),
            ),
        ];

        $this->assertSame(1, app(MeetingAttendanceSyncServiceInterface::class)->sync());
        $this->assertSame('synced', $meeting->refresh()->attendance_sync_status);
    }

    // ── 20–21. Privacy ───────────────────────────────────────────────

    public function test_sensitive_payload_values_are_not_persisted(): void
    {
        $this->enableWebhooks();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        $event = $this->sessionEvent('evt-privacy', self::STUDENT_REF, $lesson, 0, 30);
        $event['meta'] = [
            'device' => 'web',
            'join_url' => 'https://provider.test/j/secret-room',
            'email' => 'student-private@example.com',
            'access_token' => 'tok_super_secret',
            'phone_number' => '+15550001111',
        ];

        $this->postWebhook($this->envelope($meeting, [$event]))->assertStatus(202);

        $stored = LessonAttendanceEvent::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame('web', $stored->metadata['device'] ?? null);
        foreach (['join_url', 'email', 'access_token', 'phone_number'] as $key) {
            $this->assertArrayNotHasKey($key, $stored->metadata ?? []);
        }

        $row = MeetingAttendanceProviderEvent::query()->firstOrFail();
        $json = json_encode($row->normalized_events);
        $this->assertStringNotContainsString('secret-room', $json);
        $this->assertStringNotContainsString('student-private@example.com', $json);
        $this->assertStringNotContainsString('tok_super_secret', $json);
        // Participant references are hashed, never stored raw.
        $this->assertStringNotContainsString(self::STUDENT_REF, $json);
    }

    public function test_raw_meeting_links_and_participant_emails_are_not_logged(): void
    {
        $this->enableWebhooks();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        // An email-shaped participant ref that resolves nowhere — forces
        // the review path (which logs) with PII in play.
        $event = $this->sessionEvent('evt-log', 'student-private@example.com', $lesson, 0, 30);
        $event['meta'] = ['join_url' => 'https://provider.test/j/secret-room'];

        $this->postWebhook($this->envelope($meeting, [$event]))->assertStatus(202);

        $all = implode("\n", $logged);
        $this->assertStringNotContainsString('student-private@example.com', $all);
        $this->assertStringNotContainsString('secret-room', $all);
    }

    // ── 23–24. Finalization stays off ────────────────────────────────

    public function test_automated_finalization_remains_disabled_by_default(): void
    {
        $this->assertFalse(app(LessonSettings::class)->automated_finalization_enabled);
    }

    public function test_enabling_ingestion_alone_does_not_enable_automated_outcomes(): void
    {
        $this->enableWebhooks();
        $this->enableSync();
        [$lesson, $meeting] = $this->makeLessonWithMeeting();

        $this->postWebhook($this->envelope($meeting, [
            $this->sessionEvent('evt-full', self::STUDENT_REF, $lesson, 0, 55),
            $this->sessionEvent('evt-full-i', self::INSTRUCTOR_REF, $lesson, 0, 55),
        ]))->assertStatus(202);

        $this->assertSame(0, app(LessonFinalizationServiceInterface::class)->processDue());

        $lesson->refresh();
        $this->assertSame(LessonOutcome::Pending, $lesson->outcome);
        $this->assertTrue($lesson->status->isOpen());
        $this->assertSame(BookingStatus::Confirmed, $lesson->booking->status);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @return array{0: Lesson, 1: BookingMeeting} */
    private function makeLessonWithMeeting(string $meetingRef = 'mtg-1'): array
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
        ]);

        $lesson = $this->lifecycle->createFromBooking($booking);

        $meeting = BookingMeeting::factory()->created()->create([
            'booking_id' => $booking->id,
            'provider' => FakeMeetingProvider::KEY,
            'provider_meeting_id' => $meetingRef,
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
            'metadata' => [
                'attendance_participants' => [
                    'student' => self::STUDENT_REF,
                    'instructor' => self::INSTRUCTOR_REF,
                ],
            ],
        ]);

        return [$lesson, $meeting];
    }

    /** @param list<array<string, mixed>> $events */
    private function envelope(BookingMeeting $meeting, array $events, string $envelopeId = 'env-1'): array
    {
        return [
            'event_id' => $envelopeId,
            'meeting_ref' => $meeting->provider_meeting_id,
            'events' => $events,
        ];
    }

    /** @return array<string, mixed> */
    private function sessionEvent(string $id, string $participantRef, Lesson $lesson, int $startOffsetMinutes, int $durationMinutes, ?string $role = null): array
    {
        $joined = $lesson->starts_at->addMinutes($startOffsetMinutes);

        return array_filter([
            'id' => $id,
            'participant_ref' => $participantRef,
            'type' => 'session',
            'role' => $role,
            'occurred_at' => $joined->toIso8601String(),
            'joined_at' => $joined->toIso8601String(),
            'left_at' => $joined->addMinutes($durationMinutes)->toIso8601String(),
        ], fn ($value) => $value !== null);
    }

    private function postWebhook(array $payload): TestResponse
    {
        return $this->postJson($this->webhookUri(), $payload, [
            'X-Fake-Signature' => FakeMeetingProvider::sign(json_encode($payload)),
        ]);
    }

    private function webhookUri(): string
    {
        return '/api/webhooks/meetings/attendance/'.FakeMeetingProvider::KEY;
    }

    private function enableWebhooks(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->attendance_webhooks_enabled = true;
        $settings->save();
    }

    private function enableSync(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->attendance_sync_enabled = true;
        $settings->attendance_sync_delay_minutes = 0;
        $settings->save();
    }
}
