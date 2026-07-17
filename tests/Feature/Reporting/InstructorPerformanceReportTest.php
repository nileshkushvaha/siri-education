<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Enums\InstructorStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\User;
use App\Reporting\Contracts\InstructorPerformanceReportServiceInterface;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Services\Instructor\InstructorOnboardingService;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 18D — Instructor Performance report: lifecycle sources,
 * teaching activity, availability boundary (§6.5 Outcome C), demo
 * conversion reuse (§6.6 Outcome A), quality permission separation,
 * and performance bounds.
 */
class InstructorPerformanceReportTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private InstructorPerformanceReportServiceInterface $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->reports = app(InstructorPerformanceReportServiceInterface::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function manager(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    private function instructor(InstructorStatus $status = InstructorStatus::Approved): User
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->profile->update(['profile_visibility' => 'public', 'instructor_status' => $status]);
        $instructor->assignRole('instructor');

        return $instructor;
    }

    private function student(): User
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        return $student;
    }

    /** @param array<string, mixed> $overrides */
    private function bookingFor(User $instructor, ?User $student = null, ?BookingType $type = null, array $overrides = []): Booking
    {
        return Booking::factory()->confirmed()->create(array_merge([
            'booking_type_id' => ($type ?? BookingType::factory()->paid()->create())->id,
            'instructor_id' => $instructor->id,
            'student_id' => ($student ?? $this->student())->id,
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

    private function filters(): ReportFilters
    {
        return new ReportFilters(period: $this->period());
    }

    // ── Lifecycle ────────────────────────────────────────────────────────

    public function test_lifecycle_status_distribution_reports_all_cases_distinctly(): void
    {
        $admin = $this->manager();
        $this->instructor(InstructorStatus::Approved);
        $this->instructor(InstructorStatus::Vacation);
        $this->instructor(InstructorStatus::Suspended);
        $this->instructor(InstructorStatus::Submitted);

        $lifecycle = $this->reports->lifecycleSummary($admin, $this->period(), $this->filters());

        $this->assertSame(4, $lifecycle->total);
        $this->assertSame(1, $lifecycle->byStatus['approved']);
        $this->assertSame(1, $lifecycle->byStatus['vacation']);
        $this->assertSame(1, $lifecycle->byStatus['suspended']);
        $this->assertSame(1, $lifecycle->byStatus['submitted']);
        $this->assertSame(0, $lifecycle->byStatus['archived']);
        $this->assertCount(11, $lifecycle->byStatus); // every InstructorStatus case, none collapsed
    }

    public function test_approvals_in_period_use_the_structured_audit_event(): void
    {
        $admin = $this->manager();
        $approver = User::factory()->create(['status' => 'active']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $approver->assignRole('super_admin'); // Gate::before satisfies the onboarding service's review permission
        $instructor = $this->instructor(InstructorStatus::UnderReview);

        app(InstructorOnboardingService::class)->approve($approver, $instructor, 'All checks passed.');

        $lifecycle = $this->reports->lifecycleSummary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $lifecycle->approvalsInPeriod);
    }

    public function test_instructor_status_filter_narrows_results(): void
    {
        $admin = $this->manager();
        $this->instructor(InstructorStatus::Approved);
        $this->instructor(InstructorStatus::Suspended);

        $filtered = $this->reports->lifecycleSummary($admin, $this->period(), new ReportFilters(
            period: $this->period(),
            instructorStatus: InstructorStatus::Suspended,
        ));

        $this->assertSame(1, $filtered->total);
    }

    // ── Teaching activity ────────────────────────────────────────────────

    public function test_activity_summary_counts_demo_paid_completed_and_no_shows(): void
    {
        $admin = $this->manager();
        $instructor = $this->instructor();
        $demoType = BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false]);
        $paidType = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);

        $this->bookingFor($instructor, null, $demoType, ['payment_status' => BookingPaymentStatus::NotRequired, 'price' => null, 'currency' => null]);
        $paid = $this->bookingFor($instructor, null, $paidType);
        $lesson = $this->lifecycle->createFromBooking($paid->refresh());
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);

        $noShow = $this->bookingFor($instructor, null, $paidType, ['starts_at' => now()->subHours(6), 'ends_at' => now()->subHours(5)]);
        $noShowLesson = $this->lifecycle->createFromBooking($noShow->refresh());
        $this->outcomes->finalize($noShowLesson->refresh(), LessonOutcome::InstructorNoShow);

        $activity = $this->reports->activitySummary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $activity->demoBookings);
        $this->assertSame(2, $activity->paidBookings);
        $this->assertSame(1, $activity->completedLessons);
        $this->assertSame(1, $activity->instructorNoShows);
        $this->assertSame(0, $activity->studentNoShows); // never combined
    }

    public function test_unique_and_repeat_paid_students(): void
    {
        $admin = $this->manager();
        $instructor = $this->instructor();
        $paidType = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);

        $repeat = $this->student();
        $this->bookingFor($instructor, $repeat, $paidType);
        $this->bookingFor($instructor, $repeat, $paidType, ['starts_at' => now()->subDays(2)->subHour(), 'ends_at' => now()->subDays(2)]);

        $once = $this->student();
        $this->bookingFor($instructor, $once, $paidType, ['starts_at' => now()->subDays(3)->subHour(), 'ends_at' => now()->subDays(3)]);

        $activity = $this->reports->activitySummary($admin, $this->period(), $this->filters());

        $this->assertSame(2, $activity->uniqueStudents);
        $this->assertSame(2, $activity->uniquePaidStudents);
        $this->assertSame(1, $activity->repeatPaidStudents);
    }

    public function test_cancelled_bookings_do_not_count_as_teaching_activity(): void
    {
        $admin = $this->manager();
        $instructor = $this->instructor();
        $this->bookingFor($instructor, null, null, ['status' => BookingStatus::Cancelled, 'cancelled_at' => now()]);

        $activity = $this->reports->activitySummary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $activity->cancelledBookings);
        $this->assertSame(0, $activity->uniqueStudents);
        $this->assertSame(0.0, $activity->bookedTeachingHours); // cancelled never counts as booked time
    }

    // ── Availability boundary (§6.5 Outcome C) ───────────────────────────

    public function test_booked_hours_and_current_availability_are_separate_metrics_with_no_utilization_rate(): void
    {
        $admin = $this->manager();
        $instructor = $this->instructor();
        $this->bookingFor($instructor); // 1 hour booked

        TeacherAvailability::factory()->create([
            'teacher_id' => $instructor->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'is_active' => true,
        ]);
        TeacherAvailability::factory()->create([
            'teacher_id' => $instructor->id,
            'day_of_week' => 2,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'is_active' => false, // inactive — never counted
        ]);

        $activity = $this->reports->activitySummary($admin, $this->period(), $this->filters());

        $this->assertSame(1.0, $activity->bookedTeachingHours);
        $this->assertSame(2.0, $activity->publishedWeeklyAvailabilityHours);
        // No utilization property exists at all — the DTO is the structural guarantee.
        $this->assertFalse(property_exists($activity, 'utilization'));
        $this->assertFalse(property_exists($activity, 'utilizationRate'));
    }

    // ── Demo conversion (§6.6 Outcome A — reused definition) ─────────────

    public function test_demo_conversion_reuses_the_existing_definition(): void
    {
        $admin = $this->manager();
        $instructor = $this->instructor();
        $demoType = BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false]);
        $paidType = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);

        $converter = $this->student();
        $this->bookingFor($instructor, $converter, $demoType, [
            'payment_status' => BookingPaymentStatus::NotRequired, 'price' => null, 'currency' => null,
            'created_at' => now()->subDays(5),
        ]);
        $this->bookingFor($instructor, $converter, $paidType, ['created_at' => now()->subDays(3)]);

        $demoOnly = $this->student();
        $this->bookingFor($instructor, $demoOnly, $demoType, [
            'payment_status' => BookingPaymentStatus::NotRequired, 'price' => null, 'currency' => null,
            'created_at' => now()->subDays(4),
        ]);

        $conversion = $this->reports->demoConversion($admin, $this->period());

        $this->assertSame(2, $conversion->demoBookers);
        $this->assertSame(1, $conversion->convertedBookers);
        $this->assertSame(50.0, $conversion->conversionRate);
    }

    public function test_conversion_rate_is_null_not_zero_with_no_demo_bookers(): void
    {
        $admin = $this->manager();

        $conversion = $this->reports->demoConversion($admin, $this->period());

        $this->assertSame(0, $conversion->demoBookers);
        $this->assertNull($conversion->conversionRate);
    }

    // ── Quality permission separation ────────────────────────────────────

    public function test_quality_summary_requires_the_quality_permission(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->givePermissionTo(['ViewInstructorReports']); // no ViewReviewQualityReports
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($this->reports->canView($admin));
        $this->assertFalse($this->reports->canViewQuality($admin));

        $this->expectException(AuthorizationException::class);
        $this->reports->qualitySummary($admin, $this->period(), $this->filters());
    }

    public function test_performance_rows_hide_alert_counts_without_quality_permission(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->givePermissionTo(['ViewInstructorReports']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $instructor = $this->instructor();
        $this->bookingFor($instructor);
        DB::table('quality_alerts')->insert([
            'id' => (string) Str::uuid(),
            'instructor_id' => $instructor->id,
            'alert_type' => 'single_low_rating',
            'severity' => 'high',
            'status' => 'open',
            'source_type' => 'lesson_review',
            'source_id' => (string) Str::uuid(),
            'detection_fingerprint' => 'test-fp-'.$instructor->id,
            'triggered_at' => now(),
            'signal_count' => 1,
            'threshold_snapshot' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = $this->reports->performanceRows($admin, $this->period(), $this->filters());

        $this->assertNull($rows->items()[0]->activeQualityAlerts); // null — not zero — without the permission
    }

    public function test_performance_rows_show_alert_counts_with_quality_permission(): void
    {
        $admin = $this->manager(); // manager holds ViewReviewQualityReports
        $instructor = $this->instructor();
        $this->bookingFor($instructor);
        DB::table('quality_alerts')->insert([
            'id' => (string) Str::uuid(),
            'instructor_id' => $instructor->id,
            'alert_type' => 'single_low_rating',
            'severity' => 'high',
            'status' => 'open',
            'source_type' => 'lesson_review',
            'source_id' => (string) Str::uuid(),
            'detection_fingerprint' => 'test-fp2-'.$instructor->id,
            'triggered_at' => now(),
            'signal_count' => 1,
            'threshold_snapshot' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = $this->reports->performanceRows($admin, $this->period(), $this->filters());

        $this->assertSame(1, $rows->items()[0]->activeQualityAlerts);
    }

    // ── Authorization separation & privacy ───────────────────────────────

    public function test_student_report_permission_alone_does_not_grant_instructor_report(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->givePermissionTo('ViewStudentReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($this->reports->canView($admin));
    }

    public function test_performance_rows_contain_no_financial_or_kyc_fields(): void
    {
        $admin = $this->manager();
        $instructor = $this->instructor();
        $this->bookingFor($instructor);

        $rows = $this->reports->performanceRows($admin, $this->period(), $this->filters());
        $fields = array_keys(get_object_vars($rows->items()[0]));

        foreach (['earnings', 'compensation', 'settlement', 'withdrawal', 'bankAccount', 'kyc', 'email', 'phone', 'price', 'revenue'] as $forbidden) {
            $this->assertNotContains($forbidden, $fields);
        }
    }

    // ── Performance ──────────────────────────────────────────────────────

    public function test_performance_rows_query_count_is_constant(): void
    {
        $admin = $this->manager();
        $paidType = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        foreach (range(1, 5) as $i) {
            $this->bookingFor($this->instructor(), null, $paidType, ['starts_at' => now()->subDays($i)->subHour(), 'ends_at' => now()->subDays($i)]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->reports->performanceRows($admin, $this->period(), $this->filters(), 25)->items();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Paginate pair + 5 batch stat loaders + aggregates + eager loads +
        // permission lookups — constant; an N+1 would add 5 per relation.
        $this->assertLessThanOrEqual(15, $count);
    }
}
