<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Enums\RecurrenceFrequency;
use App\Enums\InstructorStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonStatus;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingMeeting;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Lesson;
use App\Models\LessonAttendanceRecord;
use App\Models\LessonTechnicalIssueReport;
use App\Models\User;
use App\Models\UserProfile;
use App\Reporting\Contracts\BookingLessonMeetingOperationsReportServiceInterface;
use App\Reporting\Enums\ReportingBookingType;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Database\Seeders\BookingPermissionSeeder;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Phase 18C — Booking, Lesson & Meeting Operations reporting service.
 * Covers authoritative booking/lesson/meeting metrics, recurrence
 * classification, historical accuracy, permission separation, masking,
 * and query-performance bounds.
 */
class BookingLessonMeetingOperationsReportTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private BookingLessonMeetingOperationsReportServiceInterface $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->reports = app(BookingLessonMeetingOperationsReportServiceInterface::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function manager(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    private function operationsOnlyAdmin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->givePermissionTo(['ViewOperationalReports', 'ViewBookingLessonReports']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    private function instructor(?Country $country = null): User
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->profile->update(array_filter([
            'profile_visibility' => 'public',
            'instructor_status' => InstructorStatus::Approved,
            'country_id' => $country?->id,
        ]));
        $instructor->assignRole('instructor');

        return $instructor;
    }

    private function studentIn(?Country $country = null): User
    {
        $student = User::factory()->create(['status' => 'active']);

        if ($country !== null) {
            UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $country->id]);
        }

        return $student;
    }

    /** @param array<string, mixed> $overrides */
    private function booking(User $instructor, User $student, BookingType $type, array $overrides = []): Booking
    {
        $endsAt = now()->subHours(2)->startOfHour();

        return Booking::factory()->confirmed()->create(array_merge([
            'booking_type_id' => $type->id,
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
            'starts_at' => $endsAt->copy()->subMinutes($type->duration_minutes),
            'ends_at' => $endsAt,
            'payment_status' => $type->is_paid ? BookingPaymentStatus::Paid : BookingPaymentStatus::NotRequired,
            'price' => $type->is_paid ? '499.00' : null,
            'currency' => $type->is_paid ? 'INR' : null,
        ], $overrides));
    }

    private function finalizedLesson(Booking $booking, LessonOutcome $outcome): Lesson
    {
        $lesson = $this->lifecycle->createFromBooking($booking->refresh());
        $this->outcomes->finalize($lesson->refresh(), $outcome);

        return $lesson->fresh();
    }

    private function period(): ReportingPeriod
    {
        return ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, 'UTC');
    }

    private function filters(ReportingPeriod $period): ReportFilters
    {
        return new ReportFilters(period: $period);
    }

    // ── Authorization ────────────────────────────────────────────────────

    public function test_authorized_operations_admin_can_view_booking_summary(): void
    {
        $admin = $this->operationsOnlyAdmin();
        $period = $this->period();

        $summary = $this->reports->bookingSummary($admin, $period, $this->filters($period));

        $this->assertSame(0, $summary->total);
    }

    public function test_unauthorized_admin_is_denied(): void
    {
        $stranger = User::factory()->create(['status' => 'active']);
        $period = $this->period();

        $this->expectException(AuthorizationException::class);
        $this->reports->bookingSummary($stranger, $period, $this->filters($period));
    }

    public function test_booking_permission_does_not_grant_meeting_section(): void
    {
        $admin = $this->operationsOnlyAdmin(); // ViewOperationalReports + ViewBookingLessonReports, no ViewMeetingReports
        $period = $this->period();

        $this->assertTrue($this->reports->canViewBookingLessonSection($admin));
        $this->assertFalse($this->reports->canViewMeetingSection($admin));

        $this->expectException(AuthorizationException::class);
        $this->reports->meetingSummary($admin, $period, $this->filters($period));
    }

    public function test_meeting_permission_alone_without_booking_lesson_permission_is_still_denied(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->givePermissionTo(['ViewMeetingReports']); // no ViewOperationalReports/ViewBookingLessonReports
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($this->reports->canViewMeetingSection($admin));
    }

    public function test_filter_manipulation_cannot_bypass_authorization(): void
    {
        $stranger = User::factory()->create(['status' => 'active']);
        $period = $this->period();

        // Even a filter set restricted to nothing must still deny outright.
        $filters = new ReportFilters(period: $period, instructorId: 99999);

        $this->expectException(AuthorizationException::class);
        $this->reports->bookingsBySubject($stranger, $period, $filters);
    }

    // ── Booking metrics ──────────────────────────────────────────────────

    public function test_total_bookings_and_by_type(): void
    {
        $admin = $this->manager();
        $demoType = BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false, 'duration_minutes' => 30]);
        $paidType = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);
        $instructor = $this->instructor();

        $this->booking($instructor, $this->studentIn(), $demoType);
        $this->booking($instructor, $this->studentIn(), $paidType);
        $this->booking($instructor, $this->studentIn(), $paidType);

        $period = $this->period();
        $summary = $this->reports->bookingSummary($admin, $period, $this->filters($period));

        $this->assertSame(3, $summary->total);
        $this->assertSame(1, $summary->byType['free_demo']);
        $this->assertSame(2, $summary->byType['paid_one_to_one']);
    }

    public function test_by_status_breakdown(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();

        $this->booking($instructor, $this->studentIn(), $type, ['status' => BookingStatus::Confirmed]);
        $this->booking($instructor, $this->studentIn(), $type, ['status' => BookingStatus::Cancelled, 'cancelled_at' => now()]);

        $period = $this->period();
        $summary = $this->reports->bookingSummary($admin, $period, $this->filters($period));

        $this->assertSame(1, $summary->byStatus['confirmed']);
        $this->assertSame(1, $summary->byStatus['cancelled']);
    }

    public function test_booking_status_filter_narrows_results(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $this->booking($instructor, $this->studentIn(), $type, ['status' => BookingStatus::Confirmed]);
        $this->booking($instructor, $this->studentIn(), $type, ['status' => BookingStatus::Cancelled, 'cancelled_at' => now()]);

        $period = $this->period();
        $filters = new ReportFilters(period: $period, bookingStatus: BookingStatus::Cancelled);
        $summary = $this->reports->bookingSummary($admin, $period, $filters);

        $this->assertSame(1, $summary->total);
    }

    public function test_booking_type_filter_rejects_unsupported_type_value(): void
    {
        $this->assertNull(ReportingBookingType::tryFrom('group_class'));
    }

    // ── Recurrence classification (data-provenance Outcome B) ─────────────

    public function test_single_daily_weekly_recurrence_classification(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();

        $this->booking($instructor, $this->studentIn(), $type); // single (null frequency)
        $this->booking($instructor, $this->studentIn(), $type, ['recurrence_frequency' => RecurrenceFrequency::Daily]);
        $this->booking($instructor, $this->studentIn(), $type, ['recurrence_frequency' => RecurrenceFrequency::Weekly]);
        $this->booking($instructor, $this->studentIn(), $type, ['recurrence_frequency' => RecurrenceFrequency::Weekly]);

        $period = $this->period();
        $summary = $this->reports->bookingSummary($admin, $period, $this->filters($period));

        $this->assertSame(1, $summary->byRecurrence['single']);
        $this->assertSame(1, $summary->byRecurrence['daily']);
        $this->assertSame(2, $summary->byRecurrence['weekly']);
        $this->assertSame(0, $summary->byRecurrence['unknown_historical']);
    }

    public function test_historical_recurring_booking_without_frequency_column_is_unknown_not_single(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();

        // Simulates a pre-Phase-18C row: recurring_group set (old signal), recurrence_frequency null (column didn't exist yet).
        $this->booking($instructor, $this->studentIn(), $type, [
            'meta' => ['recurring_group' => (string) Str::uuid()],
            'recurrence_frequency' => null,
        ]);

        $period = $this->period();
        $summary = $this->reports->bookingSummary($admin, $period, $this->filters($period));

        $this->assertSame(0, $summary->byRecurrence['single']);
        $this->assertSame(1, $summary->byRecurrence['unknown_historical']);
    }

    public function test_genuinely_single_booking_with_no_recurring_marker_is_single(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();

        $this->booking($instructor, $this->studentIn(), $type, ['meta' => null, 'recurrence_frequency' => null]);

        $period = $this->period();
        $summary = $this->reports->bookingSummary($admin, $period, $this->filters($period));

        $this->assertSame(1, $summary->byRecurrence['single']);
        $this->assertSame(0, $summary->byRecurrence['unknown_historical']);
    }

    // ── Reschedule count (data-provenance Outcome A) ──────────────────────

    public function test_rescheduled_count_from_structured_booking_activity_action(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $booking = $this->booking($instructor, $this->studentIn(), $type);

        BookingActivity::factory()->create([
            'booking_id' => $booking->id,
            'action' => BookingActivityAction::Rescheduled,
            'actor_type' => 'student',
            'created_at' => now(),
        ]);
        BookingActivity::factory()->create([
            'booking_id' => $booking->id,
            'action' => BookingActivityAction::Confirmed,
            'actor_type' => 'system',
            'created_at' => now(),
        ]);

        $period = $this->period();
        $summary = $this->reports->bookingSummary($admin, $period, $this->filters($period));

        $this->assertSame(1, $summary->rescheduled);
    }

    // ── Booking breakdowns ───────────────────────────────────────────────

    public function test_bookings_by_country_breakdown(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $india = Country::factory()->create(['name' => 'India']);
        $uk = Country::factory()->create(['name' => 'United Kingdom']);

        $this->booking($instructor, $this->studentIn($india), $type);
        $this->booking($instructor, $this->studentIn($india), $type);
        $this->booking($instructor, $this->studentIn($uk), $type);

        $period = $this->period();
        $rows = $this->reports->bookingsByCountry($admin, $period, $this->filters($period));
        $byLabel = collect($rows)->keyBy('label');

        $this->assertSame(2, $byLabel['India']->count);
        $this->assertSame(1, $byLabel['United Kingdom']->count);
    }

    public function test_bookings_by_duration_breakdown(): void
    {
        $admin = $this->manager();
        $type60 = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);
        $instructor = $this->instructor();

        $this->booking($instructor, $this->studentIn(), $type60);
        $this->booking($instructor, $this->studentIn(), $type60);

        $period = $this->period();
        $rows = $this->reports->bookingsByDuration($admin, $period, $this->filters($period));

        $this->assertSame('60 min', $rows[0]->label);
        $this->assertSame(2, $rows[0]->count);
    }

    public function test_archived_instructor_remains_in_historical_booking_counts(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $this->booking($instructor, $this->studentIn(), $type);

        // "Archiving" an instructor in this codebase is deactivation —
        // users are never hard-deleted (bookings FK is restrictOnDelete,
        // by design: booking history must survive the account).
        $instructor->update(['status' => 'inactive']);
        $instructor->profile->update(['instructor_status' => 'archived']);

        $period = $this->period();
        $summary = $this->reports->bookingSummary($admin, $period, $this->filters($period));

        $this->assertSame(1, $summary->total);

        // The instructor breakdown still attributes the historical booking.
        $rows = $this->reports->bookingsByInstructor($admin, $period, $this->filters($period));
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]->count);
    }

    // ── Lesson outcome metrics ───────────────────────────────────────────

    public function test_lesson_outcome_summary_counts_and_no_show_separation(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();

        $this->finalizedLesson($this->booking($instructor, $this->studentIn(), $type), LessonOutcome::Completed);
        $this->finalizedLesson($this->booking($instructor, $this->studentIn(), $type), LessonOutcome::StudentNoShow);
        $this->finalizedLesson($this->booking($instructor, $this->studentIn(), $type), LessonOutcome::InstructorNoShow);
        $this->finalizedLesson($this->booking($instructor, $this->studentIn(), $type), LessonOutcome::TechnicalIssue);

        $period = $this->period();
        $summary = $this->reports->lessonOutcomeSummary($admin, $period, $this->filters($period));

        $this->assertSame(4, $summary->scheduled);
        $this->assertSame(4, $summary->finalized);
        $this->assertSame(1, $summary->byOutcome['completed']);
        $this->assertSame(1, $summary->byOutcome['student_no_show']);
        $this->assertSame(1, $summary->byOutcome['instructor_no_show']);
        $this->assertSame(1, $summary->byOutcome['technical_issue']);
        // Distinct values, never combined into one count.
        $this->assertNotSame($summary->byOutcome['student_no_show'] + $summary->byOutcome['instructor_no_show'], $summary->byOutcome['student_no_show']);
    }

    public function test_lesson_outcome_filter_narrows_results(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $this->finalizedLesson($this->booking($instructor, $this->studentIn(), $type), LessonOutcome::Completed);
        $this->finalizedLesson($this->booking($instructor, $this->studentIn(), $type), LessonOutcome::StudentNoShow);

        $period = $this->period();
        $filters = new ReportFilters(period: $period, lessonOutcome: LessonOutcome::StudentNoShow);
        $summary = $this->reports->lessonOutcomeSummary($admin, $period, $filters);

        $this->assertSame(1, $summary->finalized);
        $this->assertSame(1, $summary->byOutcome['student_no_show']);
    }

    public function test_unfinalized_past_due_lesson_is_counted_once(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $booking = $this->booking($instructor, $this->studentIn(), $type);
        $lesson = $this->lifecycle->createFromBooking($booking->refresh());
        // Never finalized — outcome stays Pending, ends_at is already in the past (booking() helper schedules 2h ago).

        $period = $this->period();
        $summary = $this->reports->lessonOutcomeSummary($admin, $period, $this->filters($period));

        $this->assertSame(1, $summary->unfinalizedPastDue);
        $this->assertSame(0, $summary->finalized);
    }

    public function test_disputed_lessons_is_a_current_state_snapshot(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $lesson = $this->finalizedLesson($this->booking($instructor, $this->studentIn(), $type), LessonOutcome::TechnicalIssue);
        $this->assertSame(LessonStatus::Disputed, $lesson->status);

        $summary = $this->reports->lessonOutcomeSummary($admin, $this->period(), $this->filters($this->period()));

        $this->assertSame(1, $summary->disputed);
    }

    public function test_multiple_technical_issue_reports_do_not_inflate_the_affected_lesson_count(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $lesson = $this->finalizedLesson($this->booking($instructor, $this->studentIn(), $type), LessonOutcome::TechnicalIssue);

        foreach (range(1, 3) as $i) {
            LessonTechnicalIssueReport::create([
                'lesson_id' => $lesson->id,
                'reported_by' => $lesson->student_id,
                'reporter' => 'student',
                'category' => 'other',
                'description' => "Report {$i}",
                'occurred_at' => now(),
                'status' => 'pending',
                'submitted_at' => now(),
            ]);
        }

        $summary = $this->reports->lessonOutcomeSummary($admin, $this->period(), $this->filters($this->period()));

        // Still exactly one lesson counted with the TechnicalIssue outcome — the "reports" count (a separate meeting metric) is what actually triples.
        $this->assertSame(1, $summary->byOutcome['technical_issue']);
    }

    // ── Actionable lesson table ──────────────────────────────────────────

    public function test_lessons_in_period_table_masks_student_identity_without_permission(): void
    {
        $admin = $this->operationsOnlyAdmin(); // no ViewStudentReports
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $student = User::factory()->create(['status' => 'active', 'first_name' => 'Nilesh', 'last_name' => 'Kushvaha']);
        $this->finalizedLesson($this->booking($instructor, $student, $type), LessonOutcome::Completed);

        $rows = $this->reports->lessonsInPeriod($admin, $this->period(), $this->filters($this->period()));

        $this->assertSame('N***', $rows->items()[0]->studentLabel);
    }

    public function test_lessons_in_period_table_shows_full_identity_with_permission(): void
    {
        $admin = $this->manager(); // manager holds ViewStudentReports via ReportingPermissionSeeder
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $student = User::factory()->create(['status' => 'active', 'first_name' => 'Nilesh', 'last_name' => 'Kushvaha']);
        $this->finalizedLesson($this->booking($instructor, $student, $type), LessonOutcome::Completed);

        $rows = $this->reports->lessonsInPeriod($admin, $this->period(), $this->filters($this->period()));

        $this->assertSame('Nilesh Kushvaha', $rows->items()[0]->studentLabel);
    }

    public function test_drill_down_link_absent_without_update_permission(): void
    {
        $admin = $this->operationsOnlyAdmin(); // no Update:Booking
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $this->finalizedLesson($this->booking($instructor, $this->studentIn(), $type), LessonOutcome::Completed);

        $rows = $this->reports->lessonsInPeriod($admin, $this->period(), $this->filters($this->period()));

        $this->assertNull($rows->items()[0]->bookingViewUrl);
    }

    public function test_drill_down_link_present_with_update_permission(): void
    {
        $this->seed(BookingPermissionSeeder::class);
        $admin = $this->manager();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $this->finalizedLesson($this->booking($instructor, $this->studentIn(), $type), LessonOutcome::Completed);

        $rows = $this->reports->lessonsInPeriod($admin, $this->period(), $this->filters($this->period()));

        $this->assertNotNull($rows->items()[0]->bookingViewUrl);
    }

    public function test_lessons_in_period_table_is_paginated(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();

        foreach (range(1, 3) as $i) {
            $this->finalizedLesson($this->booking($instructor, $this->studentIn(), $type), LessonOutcome::Completed);
        }

        $rows = $this->reports->lessonsInPeriod($admin, $this->period(), $this->filters($this->period()), perPage: 2);

        $this->assertCount(2, $rows->items());
        $this->assertSame(3, $rows->total());
    }

    // ── Meeting metrics ──────────────────────────────────────────────────

    public function test_meeting_created_and_failed_counts(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();

        $bookingA = $this->booking($instructor, $this->studentIn(), $type);
        BookingMeeting::factory()->create(['booking_id' => $bookingA->id, 'status' => MeetingStatus::Created, 'created_at' => now()]);

        $bookingB = $this->booking($instructor, $this->studentIn(), $type);
        BookingMeeting::factory()->create(['booking_id' => $bookingB->id, 'status' => MeetingStatus::Failed, 'created_at' => now()]);

        $summary = $this->reports->meetingSummary($admin, $this->period(), $this->filters($this->period()));

        $this->assertSame(1, $summary->created);
        $this->assertSame(1, $summary->failed);
    }

    public function test_missing_meeting_for_confirmed_booking(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $this->booking($instructor, $this->studentIn(), $type, ['status' => BookingStatus::Confirmed]);
        // No BookingMeeting row at all.

        $summary = $this->reports->meetingSummary($admin, $this->period(), $this->filters($this->period()));

        $this->assertSame(1, $summary->missingMeeting);
    }

    public function test_join_evidence_counts_and_missing_evidence_is_never_a_no_show(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $booking = $this->booking($instructor, $this->studentIn(), $type);
        $lesson = $this->lifecycle->createFromBooking($booking->refresh());

        LessonAttendanceRecord::factory()->create([
            'lesson_id' => $lesson->id,
            'booking_id' => $booking->id,
            'student_first_joined_at' => now(),
            'instructor_first_joined_at' => null, // no evidence yet — never inferred as absence
        ]);

        $summary = $this->reports->meetingSummary($admin, $this->period(), $this->filters($this->period()));

        $this->assertSame(1, $summary->studentJoined);
        $this->assertSame(0, $summary->instructorJoined);
        $this->assertSame(0, $summary->bothJoined);
    }

    public function test_both_joined_requires_both_timestamps(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $booking = $this->booking($instructor, $this->studentIn(), $type);
        $lesson = $this->lifecycle->createFromBooking($booking->refresh());

        LessonAttendanceRecord::factory()->create([
            'lesson_id' => $lesson->id,
            'booking_id' => $booking->id,
            'student_first_joined_at' => now(),
            'instructor_first_joined_at' => now(),
        ]);

        $summary = $this->reports->meetingSummary($admin, $this->period(), $this->filters($this->period()));

        $this->assertSame(1, $summary->bothJoined);
    }

    public function test_technical_issue_report_count(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        $booking = $this->booking($instructor, $this->studentIn(), $type);
        $lesson = $this->lifecycle->createFromBooking($booking->refresh());

        LessonTechnicalIssueReport::query()->create([
            'lesson_id' => $lesson->id,
            'reported_by' => $lesson->student_id,
            'reporter' => 'student',
            'category' => 'other',
            'description' => 'Audio dropped repeatedly.',
            'occurred_at' => now(),
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        $summary = $this->reports->meetingSummary($admin, $this->period(), $this->filters($this->period()));

        $this->assertSame(1, $summary->technicalIssueReports);
    }

    public function test_meeting_issues_table_lists_failed_and_missing(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();

        $failed = $this->booking($instructor, $this->studentIn(), $type, ['status' => BookingStatus::Confirmed]);
        BookingMeeting::factory()->create(['booking_id' => $failed->id, 'status' => MeetingStatus::Failed]);

        $missing = $this->booking($instructor, $this->studentIn(), $type, ['status' => BookingStatus::Confirmed]);

        $rows = $this->reports->meetingIssues($admin, $this->period(), $this->filters($this->period()));

        $this->assertSame(2, $rows->total());
    }

    // ── Performance ──────────────────────────────────────────────────────

    public function test_booking_summary_uses_a_bounded_number_of_queries(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        foreach (range(1, 5) as $i) {
            $this->booking($instructor, $this->studentIn(), $type);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->reports->bookingSummary($admin, $this->period(), $this->filters($this->period()));
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Summary = total + byType + byStatus + byRecurrence + rescheduled — a handful of
        // aggregate queries, never proportional to the number of bookings (5 here).
        $this->assertLessThanOrEqual(10, $queryCount);
    }

    public function test_lessons_in_period_table_does_not_grow_queries_with_row_count(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = $this->instructor();
        foreach (range(1, 5) as $i) {
            $this->finalizedLesson($this->booking($instructor, $this->studentIn(), $type), LessonOutcome::Completed);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->reports->lessonsInPeriod($admin, $this->period(), $this->filters($this->period()), perPage: 25)->items();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Constant regardless of row count: paginate pair + one batch per
        // eager-loaded relation (booking, type, meeting, student, instructor,
        // subject) + permission lookups ≈ 11. A single N+1 relation would add
        // 5 more here (one per row) and blow straight past this bound.
        $this->assertLessThanOrEqual(12, $queryCount);
    }
}
