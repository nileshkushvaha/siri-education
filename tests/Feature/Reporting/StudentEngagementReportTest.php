<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\RecurrenceFrequency;
use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use App\Reporting\Contracts\StudentEngagementReportServiceInterface;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Student Engagement report: definition boundaries (§6),
 * summary metrics, breakdowns, historical behavior, masking/drill-down
 * and performance bounds.
 */
class StudentEngagementReportTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private StudentEngagementReportServiceInterface $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->reports = app(StudentEngagementReportServiceInterface::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function manager(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    /** @param array<string, mixed> $profile */
    private function student(array $attributes = [], array $profile = []): User
    {
        $student = User::factory()->create(array_merge(['status' => 'active'], $attributes));
        $student->assignRole('student');

        if ($profile !== []) {
            UserProfile::updateOrCreate(['user_id' => $student->id], $profile);
        }

        return $student;
    }

    private function instructor(): User
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->profile->update(['profile_visibility' => 'public', 'instructor_status' => InstructorStatus::Approved]);
        $instructor->assignRole('instructor');

        return $instructor;
    }

    /** @param array<string, mixed> $overrides */
    private function bookingFor(User $student, ?User $instructor = null, array $overrides = []): Booking
    {
        return Booking::factory()->confirmed()->create(array_merge([
            'booking_type_id' => BookingType::factory()->paid()->create(),
            'instructor_id' => ($instructor ?? $this->instructor())->id,
            'student_id' => $student->id,
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHours(2),
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ], $overrides));
    }

    private function period(): ReportingPeriod
    {
        return ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, 'UTC');
    }

    private function filters(?ReportingPeriod $period = null): ReportFilters
    {
        return new ReportFilters(period: $period ?? $this->period());
    }

    private function subject(string $name = 'Maths', string $slug = 'maths'): Subject
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);

        return Subject::query()->firstOrCreate(['slug' => $slug], ['academic_category_id' => $category->id, 'name' => $name, 'status' => 'active']);
    }

    // ── Definition boundaries (§6) ────────────────────────────────────────

    public function test_account_status_and_engaged_in_period_are_distinct_definitions(): void
    {
        $admin = $this->manager();
        // Account-status-active student with NO activity in period.
        $this->student([], ['student_status' => StudentStatus::Active]);
        // Registered student WITH a booking in period.
        $engaged = $this->student([], ['student_status' => StudentStatus::Registered]);
        $this->bookingFor($engaged);

        $summary = $this->reports->summary($admin, $this->period(), $this->filters());

        // One account currently "active" by status; one student "engaged in period" — different students, different meanings.
        $this->assertSame(1, $summary->byAccountStatus['active']);
        $this->assertSame(1, $summary->engagedInPeriod);
    }

    public function test_without_recent_activity_excludes_suspended_and_archived_accounts(): void
    {
        $admin = $this->manager();
        $this->student([], ['student_status' => StudentStatus::Registered]); // no activity → counted
        $this->student([], ['student_status' => StudentStatus::Suspended]); // excluded
        $this->student([], ['student_status' => StudentStatus::Archived]);  // excluded

        $summary = $this->reports->summary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $summary->withoutRecentLearningActivity);
    }

    public function test_engaged_student_is_not_counted_as_without_recent_activity(): void
    {
        $admin = $this->manager();
        $engaged = $this->student([], ['student_status' => StudentStatus::Active]);
        $this->bookingFor($engaged);

        $summary = $this->reports->summary($admin, $this->period(), $this->filters());

        $this->assertSame(0, $summary->withoutRecentLearningActivity);
    }

    // ── Summary metrics ──────────────────────────────────────────────────

    public function test_total_new_and_verified_students(): void
    {
        $admin = $this->manager();
        $this->student(['email_verified_at' => now()]);
        $this->student(['email_verified_at' => null, 'created_at' => now()->subDays(60)]);

        $summary = $this->reports->summary($admin, $this->period(), $this->filters());

        $this->assertSame(2, $summary->totalStudents);
        $this->assertSame(1, $summary->newInPeriod); // the 60-day-old account is outside the 30-day period
        $this->assertSame(1, $summary->verifiedTotal);
        $this->assertSame(1, $summary->verifiedInPeriod);
    }

    public function test_students_with_bookings_and_completed_lessons(): void
    {
        $admin = $this->manager();
        $booked = $this->student();
        $booking = $this->bookingFor($booked);
        $lesson = $this->lifecycle->createFromBooking($booking->refresh());
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);

        $this->student(); // no activity

        $summary = $this->reports->summary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $summary->withBookingsInPeriod);
        $this->assertSame(1, $summary->withCompletedLessonsInPeriod);
        $this->assertSame(1, $summary->engagedInPeriod);
    }

    public function test_students_with_active_learning_plans_and_goals(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        $subject = $this->subject();

        $goal = DB::table('student_learning_goals')->insertGetId([
            'user_id' => $student->id,
            'subject_id' => $subject->id,
            'title' => 'Master algebra',
            'type' => 'skill',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        StudentLearningPlan::query()->create([
            'student_user_id' => $student->id,
            'learning_goal_id' => $goal,
            'subject_id' => $subject->id,
            'title' => 'Algebra plan',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $summary = $this->reports->summary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $summary->withActiveLearningPlans);
        $this->assertSame(1, $summary->withActiveLearningGoals);
    }

    public function test_homework_and_review_participation(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        $instructor = $this->instructor();
        $booking = $this->bookingFor($student, $instructor);

        DB::table('homework_assignments')->insert([
            'id' => (string) Str::uuid(),
            'booking_id' => $booking->id,
            'teacher_id' => $instructor->id,
            'student_id' => $student->id,
            'subject' => 'maths',
            'title' => 'Worksheet 1',
            'due_at' => now()->addDay(),
            'status' => 'submitted',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = $this->reports->summary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $summary->withHomeworkActivityInPeriod);
    }

    public function test_lifetime_booking_buckets(): void
    {
        $admin = $this->manager();
        $instructor = $this->instructor();

        $none = $this->student();
        $one = $this->student();
        $this->bookingFor($one, $instructor);
        $three = $this->student();
        foreach (range(1, 3) as $i) {
            $this->bookingFor($three, $instructor, ['starts_at' => now()->subDays($i)->subHour(), 'ends_at' => now()->subDays($i)]);
        }

        $summary = $this->reports->summary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $summary->lifetimeBookingBuckets['0']);
        $this->assertSame(1, $summary->lifetimeBookingBuckets['1']);
        $this->assertSame(1, $summary->lifetimeBookingBuckets['2-5']);
    }

    // ── Recurrence participation ─────────────────────────────────────────

    public function test_recurring_participation_and_unknown_historical_never_counted_as_single(): void
    {
        $admin = $this->manager();
        $instructor = $this->instructor();

        $single = $this->student();
        $this->bookingFor($single, $instructor, ['recurrence_frequency' => null, 'meta' => null]);

        $weekly = $this->student();
        $this->bookingFor($weekly, $instructor, ['recurrence_frequency' => RecurrenceFrequency::Weekly]);

        $historical = $this->student();
        $this->bookingFor($historical, $instructor, ['recurrence_frequency' => null, 'meta' => ['recurring_group' => (string) Str::uuid()]]);

        $summary = $this->reports->summary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $summary->recurringParticipation['single']);
        $this->assertSame(1, $summary->recurringParticipation['weekly']);
        $this->assertSame(1, $summary->recurringParticipation['unknown_historical']);
    }

    // ── Breakdowns & historical behavior ─────────────────────────────────

    public function test_country_breakdown_and_filter(): void
    {
        $admin = $this->manager();
        $india = Country::factory()->create(['name' => 'India']);
        $uk = Country::factory()->create(['name' => 'United Kingdom']);

        $this->student([], ['country_id' => $india->id]);
        $this->student([], ['country_id' => $india->id]);
        $this->student([], ['country_id' => $uk->id]);

        $rows = collect($this->reports->byCountry($admin, $this->period(), $this->filters()))->keyBy('label');
        $this->assertSame(2, $rows['India']->count);
        $this->assertSame(1, $rows['United Kingdom']->count);

        $filtered = $this->reports->summary($admin, $this->period(), new ReportFilters(period: $this->period(), countryId: $india->id));
        $this->assertSame(2, $filtered->totalStudents);
    }

    public function test_preferred_and_booked_subject_are_separate_breakdowns(): void
    {
        $admin = $this->manager();
        $maths = $this->subject('Maths', 'maths');
        $physics = $this->subject('Physics', 'physics');
        $student = $this->student();

        DB::table('student_preferred_subjects')->insert([
            'user_id' => $student->id, 'subject_id' => $physics->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $instructor = $this->instructor();
        $booking = $this->bookingFor($student, $instructor);
        $lesson = $this->lifecycle->createFromBooking($booking->refresh());
        DB::table('lessons')->where('id', $lesson->id)->update(['subject_id' => $maths->id]);

        $preferred = collect($this->reports->byPreferredSubject($admin, $this->period(), $this->filters()))->keyBy('label');
        $booked = collect($this->reports->byBookedSubject($admin, $this->period(), $this->filters()))->keyBy('label');

        $this->assertSame(1, $preferred['Physics']->count);
        $this->assertFalse($preferred->has('Maths'));
        $this->assertSame(1, $booked['Maths']->count);
        $this->assertFalse($booked->has('Physics'));
    }

    public function test_registration_trend_is_zero_filled_and_bounded(): void
    {
        $admin = $this->manager();
        $this->student(['created_at' => now()->subDays(2)]);

        $trend = $this->reports->registrationTrend($admin, $this->period(), $this->filters());

        $this->assertCount(30, $trend);
        $this->assertSame(1, array_sum($trend));
        $this->assertContains(0, $trend); // zero-filled buckets present
    }

    public function test_suspended_student_remains_in_historical_totals(): void
    {
        $admin = $this->manager();
        $student = $this->student([], ['student_status' => StudentStatus::Registered]);
        $this->bookingFor($student);

        $student->profile->update(['student_status' => StudentStatus::Suspended]);

        $summary = $this->reports->summary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $summary->totalStudents);
        $this->assertSame(1, $summary->withBookingsInPeriod); // history survives the status change
        $this->assertSame(1, $summary->byAccountStatus['suspended']);
    }

    // ── Authorization & privacy ──────────────────────────────────────────

    public function test_unauthorized_user_is_denied(): void
    {
        $stranger = User::factory()->create(['status' => 'active']);

        $this->expectException(AuthorizationException::class);
        $this->reports->summary($stranger, $this->period(), $this->filters());
    }

    public function test_instructor_report_permission_alone_does_not_grant_student_report(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->givePermissionTo('ViewInstructorReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($this->reports->canView($admin));
    }

    public function test_engagement_rows_contain_no_email_phone_or_financial_fields(): void
    {
        $admin = $this->manager();
        $student = $this->student(['email' => 'private-student@example.com']);
        $this->bookingFor($student);

        $rows = $this->reports->engagementRows($admin, $this->period(), $this->filters());
        $fields = array_keys(get_object_vars($rows->items()[0]));

        foreach (['email', 'phone', 'walletBalance', 'price', 'revenue', 'lifetimeValue'] as $forbidden) {
            $this->assertNotContains($forbidden, $fields);
        }
    }

    public function test_drill_down_requires_target_view_permission(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->givePermissionTo(['ViewStudentReports']); // report access, but no View:User
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $student = $this->student();
        $this->bookingFor($student);

        $rows = $this->reports->engagementRows($admin, $this->period(), $this->filters());

        $this->assertNull($rows->items()[0]->drillDownUrl);
    }

    // ── Performance ──────────────────────────────────────────────────────

    public function test_engagement_rows_query_count_is_constant(): void
    {
        $admin = $this->manager();
        $instructor = $this->instructor();
        foreach (range(1, 5) as $i) {
            $this->bookingFor($this->student(), $instructor, ['starts_at' => now()->subDays($i)->subHour(), 'ends_at' => now()->subDays($i)]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->reports->engagementRows($admin, $this->period(), $this->filters(), 25)->items();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Paginate pair + profile/country eager loads + permission lookups —
        // constant; a single N+1 relation would add 5 more here.
        $this->assertLessThanOrEqual(12, $count);
    }

    public function test_summary_uses_bounded_aggregate_queries(): void
    {
        $admin = $this->manager();
        foreach (range(1, 5) as $i) {
            $this->student();
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->reports->summary($admin, $this->period(), $this->filters());
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One aggregate query per summary component — never proportional to student count.
        $this->assertLessThanOrEqual(20, $count);
    }
}
