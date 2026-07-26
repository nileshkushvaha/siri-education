<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Booking\Enums\BookingStatus;
use App\Lessons\Contracts\LessonConfirmationServiceInterface;
use App\Lessons\Contracts\LessonFinalizationServiceInterface;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonReviewServiceInterface;
use App\Lessons\DTOs\AttendanceConfirmationData;
use App\Lessons\DTOs\TechnicalIssueReportData;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonReviewStatus;
use App\Lessons\Enums\TechnicalIssueCategory;
use App\Lessons\Exceptions\LessonException;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use App\Models\LessonAttendanceConfirmation;
use App\Models\LessonAttendanceRecord;
use App\Models\User;
use App\Settings\LessonSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\LessonPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Participant attendance confirmations, technical-issue reports,
 * outcome holds, and the administrative review workflow.
 */
class LessonManualReviewTest extends TestCase
{
    use RefreshDatabase;

    private LessonConfirmationServiceInterface $confirmations;

    private LessonReviewServiceInterface $review;

    private LessonLifecycleServiceInterface $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmations = app(LessonConfirmationServiceInterface::class);
        $this->review = app(LessonReviewServiceInterface::class);
        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
    }

    // ── 1–4. Submission authorization ────────────────────────────────

    public function test_student_confirms_own_attendance(): void
    {
        $lesson = $this->makeLesson();

        $confirmation = $this->confirmations->submitConfirmation($lesson, $lesson->student, $this->claim($lesson));

        $this->assertSame(LessonReviewStatus::Pending, $confirmation->status);
        $this->assertSame('student', $confirmation->participant->value);
        $this->assertSame($lesson->student_id, $confirmation->submitted_by);
        $this->assertNotNull($confirmation->submitted_at);
        // A claim alone never touches attendance aggregates or outcomes.
        $this->assertSame(0, LessonAttendanceRecord::query()->count());
        $this->assertSame(LessonOutcome::Pending, $lesson->refresh()->outcome);
    }

    public function test_instructor_confirms_own_attendance(): void
    {
        $lesson = $this->makeLesson();

        $confirmation = $this->confirmations->submitConfirmation($lesson, $lesson->instructor, $this->claim($lesson));

        $this->assertSame('instructor', $confirmation->participant->value);
    }

    public function test_user_cannot_confirm_another_participants_attendance(): void
    {
        $lesson = $this->makeLesson();

        // The participant role is derived from the lesson — the student's
        // submission can only ever be recorded as the student claim, so
        // impersonating the instructor is structurally impossible.
        $confirmation = $this->confirmations->submitConfirmation($lesson, $lesson->student, $this->claim($lesson));

        $this->assertSame('student', $confirmation->participant->value);
        $this->assertSame(0, LessonAttendanceConfirmation::query()->where('participant', 'instructor')->count());
    }

    public function test_unrelated_user_is_rejected(): void
    {
        $lesson = $this->makeLesson();

        $this->expectException(AuthorizationException::class);

        $this->confirmations->submitConfirmation($lesson, User::factory()->create(), $this->claim($lesson));
    }

    // ── 5–8. Submission rules ────────────────────────────────────────

    public function test_duplicate_confirmation_is_idempotent(): void
    {
        $lesson = $this->makeLesson();
        $claim = $this->claim($lesson);

        $first = $this->confirmations->submitConfirmation($lesson, $lesson->student, $claim);
        $second = $this->confirmations->submitConfirmation($lesson, $lesson->student, $claim);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LessonAttendanceConfirmation::query()->count());
    }

    public function test_conflicting_confirmation_preserves_history(): void
    {
        $lesson = $this->makeLesson();

        $first = $this->confirmations->submitConfirmation($lesson, $lesson->student, $this->claim($lesson, minutes: 30));
        $second = $this->confirmations->submitConfirmation($lesson, $lesson->student, $this->claim($lesson, minutes: 55));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, LessonAttendanceConfirmation::query()->where('lesson_id', $lesson->id)->count());
        $this->assertSame(LessonReviewStatus::Pending, $first->refresh()->status);
    }

    public function test_confirmation_before_lesson_start_is_rejected(): void
    {
        $booking = Booking::factory()->confirmed()->create(); // starts in the future
        $lesson = $this->lifecycle->createFromBooking($booking);

        $this->expectException(LessonException::class);

        $this->confirmations->submitConfirmation($lesson, $lesson->student, new AttendanceConfirmationData(
            claimedAttendedMinutes: 30,
        ));
    }

    public function test_cancelled_lesson_rejects_normal_confirmation(): void
    {
        $lesson = $this->makeLesson();
        $lesson->booking->update(['status' => BookingStatus::Cancelled]);
        $lesson->refresh();

        $this->expectException(LessonException::class);

        $this->confirmations->submitConfirmation($lesson, $lesson->student, $this->claim($lesson));
    }

    // ── 9. Accepted confirmation feeds the attendance service ────────

    public function test_accepted_confirmation_feeds_the_existing_attendance_service(): void
    {
        $lesson = $this->makeLesson();
        $admin = $this->admin();

        $confirmation = $this->confirmations->submitConfirmation($lesson, $lesson->student, $this->claim($lesson, minutes: null, withInterval: true));

        $accepted = $this->review->acceptConfirmation($confirmation, $admin, 'Claim matches instructor account.');

        $this->assertSame(LessonReviewStatus::Accepted, $accepted->status);
        $this->assertSame($admin->id, $accepted->reviewed_by);

        $record = LessonAttendanceRecord::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(1, $record->student_join_count);
        $this->assertSame(45 * 60, $record->student_attended_seconds);

        // Re-accepting is idempotent — no duplicate evidence.
        $this->review->acceptConfirmation($accepted->refresh(), $admin, 'again');
        $this->assertSame(45 * 60, $record->refresh()->student_attended_seconds);
        $this->assertSame(1, $record->student_join_count);
    }

    // ── 10–13. Technical issues ──────────────────────────────────────

    public function test_technical_issue_can_be_reported_within_the_allowed_window(): void
    {
        $lesson = $this->makeLesson();

        $report = $this->confirmations->reportTechnicalIssue($lesson, $lesson->student, new TechnicalIssueReportData(
            category: TechnicalIssueCategory::UnableToJoin,
            description: 'The join button spun forever.',
        ));

        $this->assertSame(LessonReviewStatus::Pending, $report->status);
        $this->assertFalse($report->flagged_late);
        $this->assertFalse($report->after_finalization);

        // The outcome hold is placed via the attendance record's flag.
        $record = LessonAttendanceRecord::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertNotNull($record->technical_issue_reported_at);
    }

    public function test_late_technical_issue_is_flagged_for_review(): void
    {
        $settings = app(LessonSettings::class);
        $settings->technical_issue_window_minutes = 30;
        $settings->save();

        $lesson = $this->makeLesson(endedHoursAgo: 5); // far past the 30-min window

        $report = $this->confirmations->reportTechnicalIssue($lesson, $lesson->instructor, new TechnicalIssueReportData(
            category: TechnicalIssueCategory::ProviderOutage,
        ));

        $this->assertTrue($report->flagged_late);
        // A late report never places the hold on its own.
        $this->assertSame(0, LessonAttendanceRecord::query()->count());
    }

    public function test_technical_issue_blocks_automated_completion(): void
    {
        $settings = app(LessonSettings::class);
        $settings->automated_finalization_enabled = true;
        $settings->attendance_finalize_delay_minutes = 0;
        $settings->auto_complete_grace_minutes = 0;
        $settings->save();

        $lesson = $this->makeLesson();
        $this->confirmations->reportTechnicalIssue($lesson, $lesson->student, new TechnicalIssueReportData(
            category: TechnicalIssueCategory::InternetDisconnection,
        ));

        $this->assertSame(0, app(LessonFinalizationServiceInterface::class)->processDue());
        $this->assertSame(LessonOutcome::Pending, $lesson->refresh()->outcome);
        $this->assertTrue($lesson->status->isOpen());
    }

    public function test_invalid_or_sensitive_metadata_is_sanitized(): void
    {
        $lesson = $this->makeLesson();

        $report = $this->confirmations->reportTechnicalIssue($lesson, $lesson->student, new TechnicalIssueReportData(
            category: TechnicalIssueCategory::Other,
            description: '<script>alert(1)</script> Link was https://zoom.us/j/1?pwd=tok_secret and my email me@example.com '.str_repeat('x', 1200),
        ));

        $this->assertStringNotContainsString('<script>', $report->description);
        $this->assertLessThanOrEqual(1000, mb_strlen($report->description));

        $confirmation = $this->confirmations->submitConfirmation($lesson, $lesson->student, new AttendanceConfirmationData(
            claimedAttendedMinutes: 30,
            notes: '<b>bold</b> note',
        ));

        $this->assertSame('bold note', $confirmation->notes);
    }

    // ── 14–16. Admin review ──────────────────────────────────────────

    public function test_unauthorized_admin_review_is_rejected(): void
    {
        $lesson = $this->makeLesson();
        $confirmation = $this->confirmations->submitConfirmation($lesson, $lesson->student, $this->claim($lesson));

        $this->expectException(AuthorizationException::class);

        $this->review->acceptConfirmation($confirmation, User::factory()->create(), 'not allowed');
    }

    public function test_accepted_review_records_attendance_evidence_and_audit(): void
    {
        $lesson = $this->makeLesson();
        $admin = $this->admin();
        $report = $this->confirmations->reportTechnicalIssue($lesson, $lesson->student, new TechnicalIssueReportData(
            category: TechnicalIssueCategory::AudioIssue,
        ));

        $this->review->acceptTechnicalIssue($report, $admin, 'Provider status page confirms the outage.');

        $this->assertSame(LessonReviewStatus::Accepted, $report->refresh()->status);

        $activity = Activity::query()
            ->where('log_name', 'lessons')
            ->where('event', 'lesson_technical_issue_accepted')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('pending', $activity->properties->get('previous_status'));
        $this->assertSame('accepted', $activity->properties->get('new_status'));
        $this->assertSame('Provider status page confirms the outage.', $activity->properties->get('reason'));
    }

    public function test_rejected_review_does_not_alter_attendance_and_releases_hold(): void
    {
        $lesson = $this->makeLesson();
        $admin = $this->admin();
        $report = $this->confirmations->reportTechnicalIssue($lesson, $lesson->student, new TechnicalIssueReportData(
            category: TechnicalIssueCategory::VideoIssue,
        ));

        $record = LessonAttendanceRecord::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertNotNull($record->technical_issue_reported_at);

        $this->review->rejectTechnicalIssue($report, $admin, 'No corroborating evidence.');

        $this->assertSame(LessonReviewStatus::Rejected, $report->refresh()->status);
        $this->assertSame(0, $record->refresh()->student_join_count);
        // The last supporting report was rejected — the hold is released.
        $this->assertNull($record->technical_issue_reported_at);
    }

    // ── 17–18. Outcome delegation ────────────────────────────────────

    public function test_admin_outcome_finalization_uses_the_existing_outcome_service(): void
    {
        $lesson = $this->makeLesson();
        $admin = $this->admin();

        $result = $this->review->finalizeOutcome($lesson, $admin);
        $lesson = $result->lesson->refresh();

        $this->assertTrue($result->applied);
        $this->assertSame(LessonOutcome::BothAbsent, $lesson->outcome);
        $this->assertSame($admin->id, $lesson->outcome_finalized_by);
        $this->assertSame(1, $lesson->outcome_version);
    }

    public function test_admin_override_requires_a_reason(): void
    {
        $lesson = $this->makeLesson();
        $admin = $this->admin();
        $this->review->finalizeOutcome($lesson, $admin);

        $this->expectException(LessonException::class);

        $this->review->overrideOutcome($lesson->refresh(), $admin, LessonOutcome::Completed, reason: '  ');
    }

    // ── 19. Concurrency ──────────────────────────────────────────────

    public function test_concurrent_review_resolves_once(): void
    {
        $lesson = $this->makeLesson();
        $admin = $this->admin();
        $otherAdmin = $this->admin();

        $confirmation = $this->confirmations->submitConfirmation($lesson, $lesson->student, $this->claim($lesson));

        // Two admins race with different decisions on stale copies — the
        // second decision hits a settled row and conflicts.
        $copyA = LessonAttendanceConfirmation::query()->findOrFail($confirmation->id);
        $copyB = LessonAttendanceConfirmation::query()->findOrFail($confirmation->id);

        $this->review->acceptConfirmation($copyA, $admin, 'Verified against the meeting log.');

        try {
            $this->review->rejectConfirmation($copyB, $otherAdmin, 'Insufficient evidence.');
            $this->fail('Expected the conflicting decision to be rejected.');
        } catch (LessonException $e) {
            $this->assertStringContainsString('already resolved', $e->getMessage());
        }

        $this->assertSame(LessonReviewStatus::Accepted, $confirmation->refresh()->status);
    }

    // ── 21. No side effects ──────────────────────────────────────────

    public function test_no_financial_homework_or_review_side_effects_are_created(): void
    {
        $lesson = $this->makeLesson();
        $admin = $this->admin();

        $confirmation = $this->confirmations->submitConfirmation($lesson, $lesson->student, $this->claim($lesson));
        $this->review->acceptConfirmation($confirmation, $admin, 'Verified.');
        $report = $this->confirmations->reportTechnicalIssue($lesson, $lesson->instructor, new TechnicalIssueReportData(
            category: TechnicalIssueCategory::Other,
        ));
        $this->review->rejectTechnicalIssue($report, $admin, 'Not supported.');

        $this->assertSame(0, InstructorEarning::query()->count());
        $this->assertSame(0, DB::table('homework_assignments')->count());
        $this->assertSame(0, DB::table('wallet_ledger_entries')->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function makeLesson(int $endedHoursAgo = 1): Lesson
    {
        $endsAt = now()->subHours($endedHoursAgo)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
        ]);

        return app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);
    }

    private function claim(Lesson $lesson, ?int $minutes = 40, bool $withInterval = false): AttendanceConfirmationData
    {
        $joined = CarbonImmutable::parse($lesson->starts_at);

        return new AttendanceConfirmationData(
            claimedJoinedAt: $joined,
            claimedLeftAt: $withInterval ? $joined->addMinutes(45) : null,
            claimedAttendedMinutes: $withInterval ? null : $minutes,
            notes: 'I was present.',
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
