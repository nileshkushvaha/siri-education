<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Booking\Enums\BookingStatus;
use App\Lessons\Contracts\LessonAttendanceServiceInterface;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\DTOs\AttendanceEvidenceData;
use App\Lessons\Enums\AttendanceSource;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonParticipant;
use App\Lessons\Enums\LessonStatus;
use App\Lessons\Events\LessonOutcomeFinalized;
use App\Lessons\Exceptions\LessonAttendanceException;
use App\Lessons\Exceptions\LessonOutcomeException;
use App\Lessons\Exceptions\TerminalLessonOutcomeException;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\LessonAttendanceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\LessonPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Attendance evidence ingestion, outcome determination,
 * finalization, override, idempotency, and concurrency protections.
 */
class LessonAttendanceOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private LessonAttendanceServiceInterface $attendance;

    private LessonOutcomeServiceInterface $outcomes;

    private LessonLifecycleServiceInterface $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendance = app(LessonAttendanceServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
    }

    // ── 1. Recording ──────────────────────────────────────────────────

    public function test_student_and_instructor_attendance_can_be_recorded(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);

        $studentResult = $this->attendance->record($lesson, $this->webhookEvidence(
            $lesson, LessonParticipant::Student, minutesAfterStart: 0, durationMinutes: 55, eventId: 'evt-s1',
        ));
        $instructorResult = $this->attendance->record($lesson, $this->webhookEvidence(
            $lesson, LessonParticipant::Instructor, minutesAfterStart: 2, durationMinutes: 50, eventId: 'evt-i1',
        ));

        $this->assertTrue($studentResult->applied);
        $this->assertTrue($instructorResult->applied);

        $record = $instructorResult->record;
        $this->assertSame($lesson->id, $record->lesson_id);
        $this->assertSame($lesson->booking_id, $record->booking_id);
        $this->assertSame(1, $record->student_join_count);
        $this->assertSame(55 * 60, $record->student_attended_seconds);
        $this->assertSame(1, $record->instructor_join_count);
        $this->assertSame(50 * 60, $record->instructor_attended_seconds);
        $this->assertTrue($record->student_first_joined_at->equalTo($lesson->starts_at));
        $this->assertSame(AttendanceSource::ProviderWebhook, $record->source);
    }

    public function test_instructor_can_confirm_attendance_for_own_lesson(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);

        $result = $this->attendance->record($lesson, new AttendanceEvidenceData(
            participant: LessonParticipant::Instructor,
            source: AttendanceSource::InstructorConfirmation,
            joinedAt: $lesson->starts_at,
            leftAt: $lesson->ends_at,
        ), $lesson->instructor);

        $this->assertTrue($result->applied);
        $this->assertSame(1, $result->record->instructor_join_count);
    }

    // ── 2. Idempotency ────────────────────────────────────────────────

    public function test_duplicate_provider_events_remain_idempotent(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $evidence = $this->webhookEvidence($lesson, LessonParticipant::Student, 0, 30, eventId: 'evt-dup');

        $first = $this->attendance->record($lesson, $evidence);
        $second = $this->attendance->record($lesson, $evidence);

        $this->assertTrue($first->applied);
        $this->assertFalse($second->applied);
        $this->assertSame(1, LessonAttendanceEvent::query()->where('lesson_id', $lesson->id)->count());
        $this->assertSame(30 * 60, $second->record->student_attended_seconds);
        $this->assertSame(1, $second->record->student_join_count);
    }

    // ── 3. Out-of-order merging ──────────────────────────────────────

    public function test_out_of_order_attendance_events_are_merged_correctly(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);

        // Second session (rejoin) arrives before the first session.
        $this->attendance->record($lesson, $this->webhookEvidence($lesson, LessonParticipant::Student, 40, 15, eventId: 'evt-2'));
        $result = $this->attendance->record($lesson, $this->webhookEvidence($lesson, LessonParticipant::Student, 0, 30, eventId: 'evt-1'));

        $record = $result->record;
        $this->assertSame(2, $record->student_join_count);
        $this->assertSame((30 + 15) * 60, $record->student_attended_seconds);
        $this->assertTrue($record->student_first_joined_at->equalTo($lesson->starts_at));
        $this->assertTrue($record->student_last_left_at->equalTo($lesson->starts_at->addMinutes(55)));
    }

    public function test_overlapping_provider_sessions_never_double_count(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);

        $this->attendance->record($lesson, $this->webhookEvidence($lesson, LessonParticipant::Student, 0, 30, eventId: 'evt-a'));
        $result = $this->attendance->record($lesson, $this->webhookEvidence($lesson, LessonParticipant::Student, 15, 30, eventId: 'evt-b'));

        // 0–30 and 15–45 merge to one 45-minute interval.
        $this->assertSame(45 * 60, $result->record->student_attended_seconds);
    }

    // ── 4. Authorization ─────────────────────────────────────────────

    public function test_unauthorized_instructor_cannot_modify_another_instructors_lesson(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $otherInstructor = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->attendance->record($lesson, new AttendanceEvidenceData(
            participant: LessonParticipant::Instructor,
            source: AttendanceSource::InstructorConfirmation,
            joinedAt: $lesson->starts_at,
            leftAt: $lesson->ends_at,
        ), $otherInstructor);
    }

    public function test_manual_evidence_requires_an_acting_user(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);

        $this->expectException(AuthorizationException::class);

        $this->attendance->record($lesson, new AttendanceEvidenceData(
            participant: LessonParticipant::Instructor,
            source: AttendanceSource::AdminOverride,
            joinedAt: $lesson->starts_at,
        ));
    }

    // ── 5. Completion timing ─────────────────────────────────────────

    public function test_lesson_cannot_complete_before_scheduled_end(): void
    {
        $lesson = $this->makeLesson(); // starts in the future

        $this->expectException(LessonOutcomeException::class);

        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
    }

    // ── 6. Completed outcome ─────────────────────────────────────────

    public function test_completed_outcome_is_finalized_successfully(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $this->recordBothPartiesAttended($lesson);

        $determination = $this->outcomes->determine($lesson);
        $this->assertSame(LessonOutcome::Completed, $determination->outcome);

        $result = $this->outcomes->finalize($lesson);
        $lesson = $result->lesson->refresh();

        $this->assertTrue($result->applied);
        $this->assertSame(LessonOutcome::Completed, $lesson->outcome);
        $this->assertSame(LessonStatus::Completed, $lesson->status);
        $this->assertNotNull($lesson->outcome_finalized_at);
        $this->assertSame('attendance_qualified', $lesson->outcome_reason_code);
        $this->assertSame(1, $lesson->outcome_version);
        $this->assertNotNull($lesson->attendance_record_id);
        $this->assertSame(BookingStatus::Completed, $lesson->booking->refresh()->status);
    }

    // ── 7–9. No-show determinations ──────────────────────────────────

    public function test_student_no_show_determination(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $this->attendance->record($lesson, $this->webhookEvidence($lesson, LessonParticipant::Instructor, 0, 55, eventId: 'evt-i'));

        $this->assertSame(LessonOutcome::StudentNoShow, $this->outcomes->determine($lesson)->outcome);

        $lesson = $this->outcomes->finalize($lesson)->lesson->refresh();

        $this->assertSame(LessonOutcome::StudentNoShow, $lesson->outcome);
        $this->assertSame(LessonStatus::StudentNoShow, $lesson->status);
        $this->assertSame(BookingStatus::NoShow, $lesson->booking->refresh()->status);
    }

    public function test_instructor_no_show_determination(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $this->attendance->record($lesson, $this->webhookEvidence($lesson, LessonParticipant::Student, 0, 55, eventId: 'evt-s'));

        $this->assertSame(LessonOutcome::InstructorNoShow, $this->outcomes->determine($lesson)->outcome);

        $lesson = $this->outcomes->finalize($lesson)->lesson->refresh();

        $this->assertSame(LessonOutcome::InstructorNoShow, $lesson->outcome);
        $this->assertSame(LessonStatus::InstructorNoShow, $lesson->status);
    }

    public function test_both_absent_determination(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);

        $this->assertSame(LessonOutcome::BothAbsent, $this->outcomes->determine($lesson)->outcome);

        $lesson = $this->outcomes->finalize($lesson)->lesson->refresh();

        $this->assertSame(LessonOutcome::BothAbsent, $lesson->outcome);
        $this->assertSame(LessonStatus::BothNoShow, $lesson->status);
    }

    public function test_no_show_outcome_contradicting_evidence_is_rejected(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $this->attendance->record($lesson, $this->webhookEvidence($lesson, LessonParticipant::Student, 0, 55, eventId: 'evt-s'));

        $this->expectException(LessonOutcomeException::class);

        $this->outcomes->finalize($lesson, LessonOutcome::StudentNoShow);
    }

    // ── 10. Technical issue ──────────────────────────────────────────

    public function test_technical_issue_blocks_automatic_completion(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 48);
        $this->attendance->record($lesson, new AttendanceEvidenceData(
            participant: LessonParticipant::Student,
            source: AttendanceSource::ProviderWebhook,
            joinedAt: $lesson->starts_at,
            technicalIssueReported: true,
            providerEventId: 'evt-tech',
        ));

        $this->assertSame(LessonOutcome::TechnicalIssue, $this->outcomes->determine($lesson)->outcome);

        $finalized = $this->lifecycle->autoCompleteDue();
        $lesson->refresh();

        $this->assertSame(0, $finalized);
        $this->assertSame(LessonStatus::Scheduled, $lesson->status);
        $this->assertSame(LessonOutcome::Pending, $lesson->outcome);

        // Finalizing the determined outcome parks the lesson for a human decision.
        $lesson = $this->outcomes->finalize($lesson)->lesson->refresh();
        $this->assertSame(LessonOutcome::TechnicalIssue, $lesson->outcome);
        $this->assertSame(LessonStatus::Disputed, $lesson->status);
    }

    // ── 11. Cancelled booking ────────────────────────────────────────

    public function test_cancelled_booking_rejects_attendance_finalization(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $this->attendance->record($lesson, $this->webhookEvidence($lesson, LessonParticipant::Student, 0, 30, eventId: 'evt-s'));

        $lesson->booking->update(['status' => BookingStatus::Cancelled]);

        $this->expectException(LessonAttendanceException::class);

        $this->attendance->finalize($lesson->refresh());
    }

    public function test_attendance_cannot_reactivate_a_cancelled_booking(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $lesson->booking->update(['status' => BookingStatus::Cancelled]);
        $lesson = $lesson->refresh();

        try {
            $this->attendance->record($lesson, $this->webhookEvidence($lesson, LessonParticipant::Student, 0, 30, eventId: 'evt-x'));
            $this->fail('Expected LessonAttendanceException.');
        } catch (LessonAttendanceException) {
            // expected
        }

        $this->assertSame(BookingStatus::Cancelled, $lesson->booking->refresh()->status);

        $this->expectException(LessonOutcomeException::class);
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
    }

    // ── 12. Terminal outcomes ────────────────────────────────────────

    public function test_terminal_outcome_cannot_be_changed_normally(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $this->recordBothPartiesAttended($lesson);
        $lesson = $this->outcomes->finalize($lesson)->lesson;

        // Repeating the same outcome is an idempotent no-op…
        $repeat = $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);
        $this->assertFalse($repeat->applied);

        // …but changing it without an override throws.
        $this->expectException(TerminalLessonOutcomeException::class);
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::BothAbsent);
    }

    // ── 13–14. Admin override ────────────────────────────────────────

    public function test_authorized_admin_override_requires_a_reason(): void
    {
        $lesson = $this->completedLesson();
        $admin = $this->admin();

        $this->expectException(LessonOutcomeException::class);

        $this->outcomes->override($lesson, $admin, LessonOutcome::StudentNoShow, reason: '   ');
    }

    public function test_unauthorized_user_cannot_override_outcome(): void
    {
        $lesson = $this->completedLesson();

        $this->expectException(AuthorizationException::class);

        $this->outcomes->override($lesson, User::factory()->create(), LessonOutcome::StudentNoShow, 'wrong outcome');
    }

    public function test_override_corrects_terminal_outcome_and_creates_audit_record(): void
    {
        $lesson = $this->completedLesson();
        $admin = $this->admin();

        $result = $this->outcomes->override($lesson, $admin, LessonOutcome::StudentNoShow, 'Student proved absence evidence was wrong.');
        $lesson = $result->lesson->refresh();

        $this->assertTrue($result->applied);
        $this->assertSame(LessonOutcome::StudentNoShow, $lesson->outcome);
        $this->assertSame(LessonStatus::StudentNoShow, $lesson->status);
        $this->assertSame('admin_override', $lesson->outcome_reason_code);
        $this->assertSame($admin->id, $lesson->outcome_finalized_by);
        $this->assertSame(2, $lesson->outcome_version);

        $activity = Activity::query()
            ->where('log_name', 'lessons')
            ->where('event', 'lesson_outcome_overridden')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertTrue((bool) $activity->properties->get('is_override'));
        $this->assertSame('Student proved absence evidence was wrong.', $activity->properties->get('override_reason'));
        $this->assertSame(LessonOutcome::Completed->value, $activity->properties->get('previous_outcome'));
        $this->assertSame(LessonOutcome::StudentNoShow->value, $activity->properties->get('new_outcome'));
        $this->assertSame($lesson->booking_id, $activity->properties->get('booking_id'));
    }

    // ── 15–16. Concurrency & exactly-once event ─────────────────────

    public function test_concurrent_finalization_produces_one_final_outcome(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $this->recordBothPartiesAttended($lesson);

        // Two independent (stale) copies race to finalize — the row lock
        // serializes them; the loser sees the finalized outcome and no-ops.
        $copyA = Lesson::query()->findOrFail($lesson->id);
        $copyB = Lesson::query()->findOrFail($lesson->id);

        $first = $this->outcomes->finalize($copyA);
        $second = $this->outcomes->finalize($copyB);

        $this->assertTrue($first->applied);
        $this->assertFalse($second->applied);
        $this->assertSame(1, $lesson->refresh()->outcome_version);
        $this->assertSame(LessonOutcome::Completed, $lesson->outcome);
    }

    public function test_lesson_outcome_finalized_is_dispatched_exactly_once(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $this->recordBothPartiesAttended($lesson);

        Event::fake([LessonOutcomeFinalized::class]);

        $this->outcomes->finalize($lesson);
        $this->outcomes->finalize($lesson->refresh()); // idempotent repeat
        $this->lifecycle->complete($lesson->refresh(), override: true); // legacy path re-entry

        Event::assertDispatchedTimes(LessonOutcomeFinalized::class, 1);
    }

    // ── 17. UTC safety ───────────────────────────────────────────────

    public function test_all_stored_timestamps_remain_utc_safe(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);

        $joinedKolkata = $lesson->starts_at->setTimezone('Asia/Kolkata');
        $leftKolkata = $lesson->starts_at->addMinutes(45)->setTimezone('Asia/Kolkata');

        $result = $this->attendance->record($lesson, new AttendanceEvidenceData(
            participant: LessonParticipant::Student,
            source: AttendanceSource::ProviderWebhook,
            joinedAt: $joinedKolkata,
            leftAt: $leftKolkata,
            providerEventId: 'evt-tz',
        ));

        $record = $result->record;
        $this->assertTrue($record->student_first_joined_at->equalTo($lesson->starts_at));
        $this->assertSame(45 * 60, $record->student_attended_seconds);

        $raw = LessonAttendanceEvent::query()->where('lesson_id', $lesson->id)->first();
        $this->assertSame(
            $lesson->starts_at->utc()->format('Y-m-d H:i:s'),
            $raw->getRawOriginal('joined_at'),
        );

        $lesson = $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow)->lesson->refresh();
        $this->assertSame('UTC', $lesson->outcome_finalized_at->timezoneName);
        $this->assertEqualsWithDelta(now()->utc()->timestamp, $lesson->outcome_finalized_at->timestamp, 5);
    }

    // ── Attendance finalization bridge ───────────────────────────────

    public function test_finalizing_attendance_bridges_evidence_into_lesson_statuses(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $this->attendance->record($lesson, $this->webhookEvidence($lesson, LessonParticipant::Instructor, 0, 55, eventId: 'evt-i'));

        $result = $this->attendance->finalize($lesson);
        $lesson->refresh();

        $this->assertTrue($result->applied);
        $this->assertNotNull($result->record->finalized_at);
        $this->assertSame('attended', $lesson->instructor_attendance_status->value);
        $this->assertSame('no_show', $lesson->student_attendance_status->value);

        // A sealed record never absorbs further evidence into the
        // aggregates — it lands in the late-evidence log instead (17B).
        $late = $this->attendance->record($lesson, $this->webhookEvidence($lesson, LessonParticipant::Student, 5, 10, eventId: 'evt-late'));

        $this->assertFalse($late->applied);
        $this->assertTrue($late->late);
        $this->assertNotNull($late->record->late_evidence_reported_at);
        $this->assertSame(0, $late->record->student_join_count);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function makeLesson(?int $endedHoursAgo = null): Lesson
    {
        $attributes = [];

        if ($endedHoursAgo !== null) {
            $endsAt = now()->subHours($endedHoursAgo)->startOfHour();
            $attributes = [
                'starts_at' => $endsAt->copy()->subMinutes(60),
                'ends_at' => $endsAt,
            ];
        }

        $booking = Booking::factory()->confirmed()->create($attributes);

        return $this->lifecycle->createFromBooking($booking);
    }

    private function completedLesson(): Lesson
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $this->recordBothPartiesAttended($lesson);

        return $this->outcomes->finalize($lesson)->lesson->refresh();
    }

    private function recordBothPartiesAttended(Lesson $lesson): void
    {
        $this->attendance->record($lesson, $this->webhookEvidence($lesson, LessonParticipant::Student, 0, 55, eventId: 'evt-qs'));
        $this->attendance->record($lesson, $this->webhookEvidence($lesson, LessonParticipant::Instructor, 0, 55, eventId: 'evt-qi'));
    }

    private function webhookEvidence(Lesson $lesson, LessonParticipant $participant, int $minutesAfterStart, int $durationMinutes, string $eventId): AttendanceEvidenceData
    {
        $joined = CarbonImmutable::parse($lesson->starts_at)->addMinutes($minutesAfterStart);

        return new AttendanceEvidenceData(
            participant: $participant,
            source: AttendanceSource::ProviderWebhook,
            joinedAt: $joined,
            leftAt: $joined->addMinutes($durationMinutes),
            providerReference: 'meeting-123',
            providerEventId: $eventId,
            metadata: ['provider' => 'zoom', 'join_url' => 'https://should-be-stripped.example', 'device' => 'web'],
        );
    }

    private function admin(): User
    {
        $this->seed(LessonPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('manager');

        return $admin;
    }
}
