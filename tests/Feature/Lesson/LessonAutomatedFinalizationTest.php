<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Console\Commands\FinalizeDueLessons;
use App\Lessons\Contracts\LessonAttendanceServiceInterface;
use App\Lessons\Contracts\LessonFinalizationServiceInterface;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\DTOs\AttendanceEvidenceData;
use App\Lessons\DTOs\AttendanceRecordingResult;
use App\Lessons\Enums\AttendanceSource;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonParticipant;
use App\Lessons\Enums\LessonStatus;
use App\Lessons\Events\LessonCompleted;
use App\Lessons\Events\LessonOutcomeFinalized;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use App\Models\LessonAttendanceEvent;
use App\Models\User;
use App\Settings\LessonSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\LessonPermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * The lessons:finalize-due automated finalizer: evidence
 * windows, per-outcome delays, technical-issue holds, manual/override
 * protection, idempotency, concurrency, batch isolation, and earnings
 * pipeline compatibility.
 */
class LessonAutomatedFinalizationTest extends TestCase
{
    use ManagesFinancialSettings;
    use RefreshDatabase;

    private LessonAttendanceServiceInterface $attendance;

    private LessonOutcomeServiceInterface $outcomes;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonFinalizationServiceInterface $finalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendance = app(LessonAttendanceServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->finalizer = app(LessonFinalizationServiceInterface::class);
    }

    // ── Kill switch ──────────────────────────────────────────────────

    public function test_engine_ships_disabled_and_processes_nothing_by_default(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 48);
        $this->recordBothAttended($lesson);

        $this->assertSame(0, $this->finalizer->processDue());
        $this->assertSame(LessonOutcome::Pending, $lesson->refresh()->outcome);
    }

    public function test_legacy_sweep_defers_while_engine_is_enabled(): void
    {
        $this->enableEngine();
        $this->makeLesson(endedHoursAgo: 48);

        $this->assertSame(0, $this->lifecycle->autoCompleteDue());
    }

    // ── 1–4. Determination matrix ────────────────────────────────────

    public function test_due_lesson_with_both_parties_attending_becomes_completed(): void
    {
        $this->enableEngine();
        $lesson = $this->makeLesson(endedHoursAgo: 2);
        $this->recordBothAttended($lesson);

        $this->assertSame(1, $this->finalizer->processDue());

        $lesson->refresh();
        $this->assertSame(LessonOutcome::Completed, $lesson->outcome);
        $this->assertSame(LessonStatus::Completed, $lesson->status);
        $this->assertNotNull($lesson->attendanceRecord->finalized_at);
        $this->assertSame(BookingStatus::Completed, $lesson->booking->status);
    }

    public function test_instructor_only_attendance_becomes_student_no_show(): void
    {
        $this->enableEngine();
        $lesson = $this->makeLesson(endedHoursAgo: 2);
        $this->record($lesson, LessonParticipant::Instructor, 'evt-i');

        $this->assertSame(1, $this->finalizer->processDue());

        $lesson->refresh();
        $this->assertSame(LessonOutcome::StudentNoShow, $lesson->outcome);
        $this->assertSame(LessonStatus::StudentNoShow, $lesson->status);
        $this->assertSame(BookingStatus::NoShow, $lesson->booking->status);
    }

    public function test_student_only_attendance_becomes_instructor_no_show(): void
    {
        $this->enableEngine();
        $lesson = $this->makeLesson(endedHoursAgo: 2);
        $this->record($lesson, LessonParticipant::Student, 'evt-s');

        $this->assertSame(1, $this->finalizer->processDue());

        $this->assertSame(LessonOutcome::InstructorNoShow, $lesson->refresh()->outcome);
    }

    public function test_no_attendance_becomes_both_absent(): void
    {
        $this->enableEngine();
        $lesson = $this->makeLesson(endedHoursAgo: 2);

        $this->assertSame(1, $this->finalizer->processDue());

        $lesson->refresh();
        $this->assertSame(LessonOutcome::BothAbsent, $lesson->outcome);
        $this->assertSame(LessonStatus::BothNoShow, $lesson->status);
    }

    // ── 5–6. Technical issue ─────────────────────────────────────────

    public function test_technical_issue_blocks_normal_auto_completion(): void
    {
        $this->enableEngine(); // technical_issue_window stays 1440
        $lesson = $this->makeLesson(endedHoursAgo: 2);
        $this->recordBothAttended($lesson);
        $this->attendance->record($lesson, new AttendanceEvidenceData(
            participant: LessonParticipant::Student,
            source: AttendanceSource::ProviderWebhook,
            joinedAt: CarbonImmutable::parse($lesson->starts_at),
            technicalIssueReported: true,
            providerEventId: 'evt-tech',
        ));

        $this->assertSame(0, $this->finalizer->processDue());

        $lesson->refresh();
        $this->assertSame(LessonOutcome::Pending, $lesson->outcome);
        $this->assertTrue($lesson->status->isOpen());
    }

    public function test_technical_issue_finalizes_after_its_reporting_window(): void
    {
        $this->enableEngine(['technical_issue_window_minutes' => 30]);
        $lesson = $this->makeLesson(endedHoursAgo: 2);
        $this->attendance->record($lesson, new AttendanceEvidenceData(
            participant: LessonParticipant::Student,
            source: AttendanceSource::ProviderWebhook,
            joinedAt: CarbonImmutable::parse($lesson->starts_at),
            technicalIssueReported: true,
            providerEventId: 'evt-tech',
        ));

        Event::fake([LessonCompleted::class]);

        $this->assertSame(1, $this->finalizer->processDue());

        $lesson->refresh();
        $this->assertSame(LessonOutcome::TechnicalIssue, $lesson->outcome);
        $this->assertSame(LessonStatus::Disputed, $lesson->status);
        Event::assertNotDispatched(LessonCompleted::class);
    }

    // ── 7–8. Delays ──────────────────────────────────────────────────

    public function test_lesson_is_not_processed_before_auto_completion_delay(): void
    {
        $this->enableEngine(auto_complete_grace_minutes: 240); // 4h completion delay
        $lesson = $this->makeLesson(endedHoursAgo: 2);
        $this->recordBothAttended($lesson);

        $this->assertSame(0, $this->finalizer->processDue());

        $lesson->refresh();
        // Attendance seals once its window closes, but completion waits.
        $this->assertNotNull($lesson->attendanceRecord->finalized_at);
        $this->assertSame(LessonOutcome::Pending, $lesson->outcome);
        $this->assertTrue($lesson->status->isOpen());
    }

    public function test_attendance_remains_open_until_its_evidence_delay_passes(): void
    {
        $this->enableEngine(['attendance_finalize_delay_minutes' => 180]);
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $this->recordBothAttended($lesson);

        $this->assertSame(0, $this->finalizer->processDue());

        $lesson->refresh();
        $this->assertNull($lesson->attendanceRecord->finalized_at);
        $this->assertSame(LessonOutcome::Pending, $lesson->outcome);
    }

    // ── 9–11. Protection of existing finalizations ───────────────────

    public function test_already_finalized_lessons_are_skipped(): void
    {
        $this->enableEngine();
        $lesson = $this->makeLesson(endedHoursAgo: 2);
        $this->recordBothAttended($lesson);

        $this->assertSame(1, $this->finalizer->processDue());
        $this->assertSame(0, $this->finalizer->processDue());
        $this->assertSame(1, $lesson->refresh()->outcome_version);
    }

    public function test_manual_instructor_completion_is_not_overwritten(): void
    {
        $this->enableEngine();
        $lesson = $this->makeLesson(endedHoursAgo: 2);
        $instructor = $lesson->instructor;

        $this->lifecycle->complete($lesson, $instructor, 'Great session.');

        $this->assertSame(0, $this->finalizer->processDue());

        $lesson->refresh();
        $this->assertSame(LessonOutcome::Completed, $lesson->outcome);
        $this->assertSame($instructor->id, $lesson->completed_by);
        $this->assertSame($instructor->id, $lesson->outcome_finalized_by);
        $this->assertSame(1, $lesson->outcome_version);
    }

    public function test_admin_override_is_not_overwritten(): void
    {
        $this->enableEngine();
        $this->seed(LessonPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('manager');

        $lesson = $this->makeLesson(endedHoursAgo: 2);
        $this->recordBothAttended($lesson);
        $this->finalizer->processDue();

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::StudentNoShow, 'Corrected after student complaint.');

        $this->assertSame(0, $this->finalizer->processDue());

        $lesson->refresh();
        $this->assertSame(LessonOutcome::StudentNoShow, $lesson->outcome);
        $this->assertSame(2, $lesson->outcome_version);
    }

    // ── 12. Cancelled sync ───────────────────────────────────────────

    public function test_cancelled_booking_synchronizes_only_to_cancelled_outcome(): void
    {
        $this->enableEngine();
        $lesson = $this->makeLesson(endedHoursAgo: 2);
        $this->recordBothAttended($lesson);

        // Cancelled out-of-band (listeners bypassed) — the finalizer must
        // recover the lesson to Cancelled, never to Completed.
        $lesson->booking->update(['status' => BookingStatus::Cancelled]);

        $this->assertSame(1, $this->finalizer->processDue());

        $lesson->refresh();
        $this->assertSame(LessonOutcome::Cancelled, $lesson->outcome);
        $this->assertSame(LessonStatus::Cancelled, $lesson->status);
        $this->assertSame(BookingStatus::Cancelled, $lesson->booking->status);
    }

    // ── 13–15. Idempotency, concurrency, batch isolation ─────────────

    public function test_duplicate_command_runs_remain_idempotent(): void
    {
        $this->enableEngine();
        $lesson = $this->makeLesson(endedHoursAgo: 2);
        $this->recordBothAttended($lesson);

        $this->artisan(FinalizeDueLessons::class)->expectsOutputToContain('Finalized 1 lesson(s).')->assertSuccessful();
        $this->artisan(FinalizeDueLessons::class)->expectsOutputToContain('Finalized 0 lesson(s).')->assertSuccessful();

        $this->assertSame(1, $lesson->refresh()->outcome_version);
    }

    public function test_concurrent_command_workers_finalize_once(): void
    {
        $this->enableEngine();
        $lesson = $this->makeLesson(endedHoursAgo: 2);
        $this->recordBothAttended($lesson);

        Event::fake([LessonOutcomeFinalized::class]);

        // Worker 1 finalizes mid-run; worker 2's sweep then races over the
        // same (stale-cursor) lesson — the row lock and terminal-outcome
        // guard make it a no-op.
        $this->outcomes->finalize(Lesson::query()->findOrFail($lesson->id));
        $this->assertSame(0, $this->finalizer->processDue());

        $this->assertSame(1, $lesson->refresh()->outcome_version);
        Event::assertDispatchedTimes(LessonOutcomeFinalized::class, 1);
    }

    public function test_one_failed_lesson_does_not_stop_the_batch(): void
    {
        $this->enableEngine();
        $failing = $this->makeLesson(endedHoursAgo: 3);
        $healthy = $this->makeLesson(endedHoursAgo: 2);
        $this->recordBothAttended($failing);
        $this->recordBothAttended($healthy);

        Event::listen(LessonOutcomeFinalized::class, function (LessonOutcomeFinalized $event) use ($failing): void {
            if ($event->lesson->id === $failing->id) {
                throw new RuntimeException('Downstream listener exploded.');
            }
        });

        $this->finalizer->processDue();

        $this->assertSame(LessonOutcome::Completed, $healthy->refresh()->outcome);
    }

    // ── 16. Late evidence ────────────────────────────────────────────

    public function test_late_evidence_is_recorded_but_does_not_silently_change_the_outcome(): void
    {
        $this->enableEngine();
        $lesson = $this->makeLesson(endedHoursAgo: 2);
        $this->record($lesson, LessonParticipant::Instructor, 'evt-i');
        $this->finalizer->processDue();

        $lesson->refresh();
        $this->assertSame(LessonOutcome::StudentNoShow, $lesson->outcome);

        $late = $this->record($lesson, LessonParticipant::Student, 'evt-late-student');

        $this->assertFalse($late->applied);
        $this->assertTrue($late->late);
        $this->assertSame(LessonOutcome::StudentNoShow, $lesson->refresh()->outcome);
        $this->assertNotNull($late->record->late_evidence_reported_at);
        $this->assertTrue(LessonAttendanceEvent::query()
            ->where('lesson_id', $lesson->id)->where('is_late', true)->exists());
        $this->assertTrue(Activity::query()
            ->where('log_name', 'lessons')->where('event', 'lesson_attendance_late_evidence')->exists());
    }

    // ── 17–19. Events & earnings compatibility ───────────────────────

    public function test_lesson_outcome_finalized_dispatches_once(): void
    {
        $this->enableEngine();
        $lesson = $this->makeLesson(endedHoursAgo: 2);
        $this->recordBothAttended($lesson);

        Event::fake([LessonOutcomeFinalized::class]);

        $this->finalizer->processDue();
        $this->finalizer->processDue();

        Event::assertDispatchedTimes(LessonOutcomeFinalized::class, 1);
        $this->assertSame(LessonOutcome::Completed, $lesson->refresh()->outcome);
    }

    public function test_existing_lesson_completed_earning_creation_remains_exactly_once(): void
    {
        $this->enableEngine();
        $lesson = $this->makeEarnableLesson(endedHoursAgo: 2);
        $this->recordBothAttended($lesson);

        $this->finalizer->processDue();
        $this->finalizer->processDue();

        $this->assertSame(LessonOutcome::Completed, $lesson->refresh()->outcome);
        $this->assertSame(1, InstructorEarning::query()->where('lesson_id', $lesson->id)->count());
    }

    public function test_no_earnings_are_created_for_non_completed_outcomes(): void
    {
        $this->enableEngine();
        $noShow = $this->makeEarnableLesson(endedHoursAgo: 2);
        $this->record($noShow, LessonParticipant::Instructor, 'evt-i');

        Event::fake([LessonCompleted::class]);

        $this->finalizer->processDue();

        $this->assertSame(LessonOutcome::StudentNoShow, $noShow->refresh()->outcome);
        Event::assertNotDispatched(LessonCompleted::class);
        $this->assertSame(0, InstructorEarning::query()->count());
    }

    // ── 20. Scheduler registration ───────────────────────────────────

    public function test_scheduler_registration_and_overlap_protection(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'lessons:finalize-due'));

        $this->assertNotNull($event, 'lessons:finalize-due is not registered in the scheduler.');
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    // ── 21. UTC safety ───────────────────────────────────────────────

    public function test_timezone_boundaries_remain_utc_safe(): void
    {
        $this->enableEngine();
        $lesson = $this->makeLesson(endedHoursAgo: 2);

        // Evidence reported in DST-observing zones must merge to the same
        // UTC instants the lesson was scheduled in.
        $this->attendance->record($lesson, new AttendanceEvidenceData(
            participant: LessonParticipant::Student,
            source: AttendanceSource::ProviderWebhook,
            joinedAt: CarbonImmutable::parse($lesson->starts_at)->setTimezone('Europe/London'),
            leftAt: CarbonImmutable::parse($lesson->ends_at)->setTimezone('America/New_York'),
            providerEventId: 'evt-tz-s',
        ));
        $this->record($lesson, LessonParticipant::Instructor, 'evt-tz-i');

        $this->assertSame(1, $this->finalizer->processDue());

        $lesson->refresh();
        $record = $lesson->attendanceRecord;
        $this->assertSame(LessonOutcome::Completed, $lesson->outcome);
        $this->assertTrue($record->student_first_joined_at->equalTo($lesson->starts_at));
        $this->assertTrue($record->student_last_left_at->equalTo($lesson->ends_at));
        $this->assertSame('UTC', $lesson->outcome_finalized_at->timezoneName);
        $this->assertSame('UTC', $record->finalized_at->timezoneName);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @param array<string, int|bool> $overrides */
    private function enableEngine(array $overrides = [], int $auto_complete_grace_minutes = 0): void
    {
        $settings = app(LessonSettings::class);
        $settings->automated_finalization_enabled = true;
        $settings->attendance_finalize_delay_minutes = 0;
        $settings->auto_complete_grace_minutes = $auto_complete_grace_minutes;

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }

    private function makeLesson(int $endedHoursAgo): Lesson
    {
        $endsAt = now()->subHours($endedHoursAgo)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
        ]);

        return $this->lifecycle->createFromBooking($booking);
    }

    private function makeEarnableLesson(int $endedHoursAgo): Lesson
    {
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);
        $this->setFinancialSettings(['earnings_enabled' => true]);

        $endsAt = now()->subHours($endedHoursAgo)->startOfHour();
        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);

        $lesson = $this->lifecycle->createFromBooking($booking);

        InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $lesson->instructor_id,
            'amount_minor' => 80000,
            'currency_code' => 'INR',
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
            'effective_from' => now()->subMonth(),
        ]);

        return $lesson;
    }

    private function recordBothAttended(Lesson $lesson): void
    {
        $this->record($lesson, LessonParticipant::Student, 'evt-qs');
        $this->record($lesson, LessonParticipant::Instructor, 'evt-qi');
    }

    private function record(Lesson $lesson, LessonParticipant $participant, string $eventId): AttendanceRecordingResult
    {
        $joined = CarbonImmutable::parse($lesson->starts_at);

        return $this->attendance->record($lesson, new AttendanceEvidenceData(
            participant: $participant,
            source: AttendanceSource::ProviderWebhook,
            joinedAt: $joined,
            leftAt: $joined->addMinutes(55),
            providerReference: 'meeting-123',
            providerEventId: $eventId,
        ));
    }
}
