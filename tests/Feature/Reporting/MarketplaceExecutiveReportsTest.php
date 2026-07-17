<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Booking\Enums\BookingPaymentStatus;
use App\Enums\InstructorStatus;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Subject;
use App\Models\User;
use App\Reporting\Contracts\MarketplaceExecutiveReportServiceInterface;
use App\Reporting\Contracts\MetricRegistryInterface;
use App\Reporting\Contracts\StudentEngagementReportServiceInterface;
use App\Reporting\DTOs\Marketplace\ExecutiveKpiOverviewData;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 18H — marketplace supply/demand definitions, comparison
 * boundaries, executive composition (owner equality, permission-gated
 * groups), fabrication guards, privacy and zero side effects.
 */
class MarketplaceExecutiveReportsTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceExecutiveReportServiceInterface $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->reports = app(MarketplaceExecutiveReportServiceInterface::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function manager(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    private function instructor(InstructorStatus $status = InstructorStatus::Active): User
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');
        $instructor->profile->update(['instructor_status' => $status]);

        return $instructor;
    }

    private function student(): User
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        return $student;
    }

    private function subject(string $name = 'Maths', string $slug = 'maths'): Subject
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);

        return Subject::query()->firstOrCreate(['slug' => $slug], ['academic_category_id' => $category->id, 'name' => $name, 'status' => 'active']);
    }

    private function assignSubject(User $instructor, Subject $subject): void
    {
        DB::table('teacher_subjects')->insert([
            'id' => (string) Str::uuid(),
            'teacher_id' => $instructor->id,
            'subject' => $subject->name,
            'subject_id' => $subject->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function availability(User $instructor): void
    {
        DB::table('teacher_availability')->insert([
            'id' => (string) Str::uuid(),
            'teacher_id' => $instructor->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function booking(User $student, User $instructor, ?Subject $subject = null, bool $paid = true): Booking
    {
        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => $paid ? BookingType::factory()->paid()->create() : BookingType::factory()->create(['is_paid' => false]),
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'payment_status' => $paid ? BookingPaymentStatus::Paid : BookingPaymentStatus::NotRequired,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        if ($subject !== null && DB::table('lessons')->where('booking_id', $booking->id)->doesntExist()) {
            DB::table('lessons')->insert([
                'id' => (string) Str::uuid(),
                'booking_id' => $booking->id,
                'student_id' => $student->id,
                'instructor_id' => $instructor->id,
                'subject_id' => $subject->id,
                'status' => 'scheduled',
                'starts_at' => $booking->starts_at,
                'ends_at' => $booking->ends_at,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } elseif ($subject !== null) {
            DB::table('lessons')->where('booking_id', $booking->id)->update(['subject_id' => $subject->id]);
        }

        return $booking;
    }

    private function period(): ReportingPeriod
    {
        return ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, 'UTC');
    }

    private function filters(): ReportFilters
    {
        return new ReportFilters(period: $this->period());
    }

    // ── Marketplace supply ────────────────────────────────────────────────

    public function test_supply_counts_current_lifecycle_states(): void
    {
        $admin = $this->manager();
        $this->instructor(InstructorStatus::Active);
        $this->instructor(InstructorStatus::Active);
        $this->instructor(InstructorStatus::Approved);
        $this->instructor(InstructorStatus::Vacation);
        $this->instructor(InstructorStatus::Suspended);

        $supply = $this->reports->marketplaceSupply($admin, $this->period(), $this->filters());

        $this->assertSame(5, $supply->totalInstructors);
        $this->assertSame(2, $supply->activeInstructors);
        $this->assertSame(1, $supply->approvedInstructors);
        $this->assertSame(1, $supply->onVacation);
        $this->assertSame(1, $supply->suspended);
    }

    public function test_active_instructors_without_availability_are_flagged(): void
    {
        $admin = $this->manager();
        $with = $this->instructor();
        $this->availability($with);
        $this->instructor(); // active, no availability
        $approvedNoAvailability = $this->instructor(InstructorStatus::Approved); // not Active — not in either bucket

        $supply = $this->reports->marketplaceSupply($admin, $this->period(), $this->filters());

        $this->assertSame(1, $supply->activeWithPublishedAvailability);
        $this->assertSame(1, $supply->activeWithoutPublishedAvailability);
    }

    public function test_supply_by_subject_counts_current_assignment_of_active_instructors_only(): void
    {
        $admin = $this->manager();
        $maths = $this->subject();
        $active = $this->instructor();
        $this->assignSubject($active, $maths);
        $suspended = $this->instructor(InstructorStatus::Suspended);
        $this->assignSubject($suspended, $maths);

        $supply = $this->reports->marketplaceSupply($admin, $this->period(), $this->filters());

        $this->assertCount(1, $supply->bySubject);
        $this->assertSame('Maths', $supply->bySubject[0]->label);
        $this->assertSame(1, $supply->bySubject[0]->count, 'Suspended instructors are not active supply.');
    }

    // ── Marketplace demand ────────────────────────────────────────────────

    public function test_demand_separates_demo_paid_and_recurrence_buckets(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        $instructor = $this->instructor();

        $this->booking($student, $instructor, null, paid: true);
        $this->booking($student, $instructor, null, paid: false);

        $demand = $this->reports->marketplaceDemand($admin, $this->period(), $this->filters());

        $this->assertSame(2, $demand->bookingsInPeriod);
        $this->assertSame(1, $demand->studentsWithBookings);
        $this->assertSame(1, $demand->demoBookings);
        $this->assertSame(1, $demand->paidBookings);
        $this->assertArrayHasKey('unknown_historical', $demand->byRecurrence, 'unknown_historical is always a separate bucket.');
    }

    public function test_demand_by_subject_uses_the_phase_18c_lesson_basis(): void
    {
        $admin = $this->manager();
        $maths = $this->subject();
        $physics = $this->subject('Physics', 'physics');
        $student = $this->student();
        $instructor = $this->instructor();

        $this->booking($student, $instructor, $maths);
        $this->booking($student, $instructor, $maths);
        $this->booking($student, $instructor, $physics);

        $demand = $this->reports->marketplaceDemand($admin, $this->period(), $this->filters());

        $labels = collect($demand->bySubject)->keyBy('label');
        $this->assertSame(2, $labels['Maths']->count);
        $this->assertSame(1, $labels['Physics']->count);
    }

    public function test_goal_and_preference_signals_are_interest_not_bookings(): void
    {
        $admin = $this->manager();
        $maths = $this->subject();
        $student = $this->student();

        DB::table('student_learning_goals')->insert([
            'user_id' => $student->id, 'subject_id' => $maths->id,
            'title' => 'Goal', 'type' => 'academic', 'status' => 'active', 'priority' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $demand = $this->reports->marketplaceDemand($admin, $this->period(), $this->filters());

        $this->assertSame(0, $demand->bookingsInPeriod, 'A goal is never counted as booking demand.');
        $this->assertSame(1, $demand->activeGoalDemandBySubject[0]->count);
    }

    // ── Comparison ────────────────────────────────────────────────────────

    public function test_subject_gaps_list_only_mismatches(): void
    {
        $admin = $this->manager();
        $maths = $this->subject();
        $physics = $this->subject('Physics', 'physics');
        $chemistry = $this->subject('Chemistry', 'chemistry');
        $student = $this->student();

        // Maths: demand + supply → NOT a gap.
        $mathsTeacher = $this->instructor();
        $this->assignSubject($mathsTeacher, $maths);
        $this->booking($student, $mathsTeacher, $maths);

        // Physics: demand, no active supply → gap.
        $this->booking($student, $mathsTeacher, $physics);

        // Chemistry: supply, no demand → gap.
        $chemTeacher = $this->instructor();
        $this->assignSubject($chemTeacher, $chemistry);

        $comparison = $this->reports->marketplaceComparison($admin, $this->period(), $this->filters());

        $labels = collect($comparison->subjectGaps)->pluck('subjectLabel');
        $this->assertContains('Physics', $labels);
        $this->assertContains('Chemistry', $labels);
        $this->assertNotContains('Maths', $labels, 'Subjects with both sides never appear as gaps.');
    }

    public function test_demand_per_active_instructor_is_null_without_active_instructors(): void
    {
        $admin = $this->manager();

        $comparison = $this->reports->marketplaceComparison($admin, $this->period(), $this->filters());

        $this->assertNull($comparison->demandPerActiveInstructor, 'Never a fabricated ratio at zero denominator.');
    }

    public function test_no_marketplace_score_ranking_or_search_metric_exists(): void
    {
        $registry = app(MetricRegistryInterface::class);

        foreach (['marketplace_health_score', 'instructor_ranking', 'instructor_utilization', 'search_events', 'profile_views', 'waitlist_demand', 'unmet_demand'] as $key) {
            $this->assertNull($registry->find($key), "'{$key}' must not exist (§4 fabrication guards).");
        }
    }

    // ── Executive composition ─────────────────────────────────────────────

    public function test_executive_kpis_equal_their_owning_service_results(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        $instructor = $this->instructor();
        $this->booking($student, $instructor, $this->subject());

        $overview = $this->reports->executiveOverview($admin, $this->period(), $this->filters());

        // Same DTO class, same values as the owner produces directly.
        $ownerSummary = app(StudentEngagementReportServiceInterface::class)->summary($admin, $this->period(), $this->filters());

        $this->assertNotNull($overview->students);
        $this->assertSame($ownerSummary->totalStudents, $overview->students->totalStudents);
        $this->assertSame($ownerSummary->newInPeriod, $overview->students->newInPeriod);
        $this->assertSame($ownerSummary->engagedInPeriod, $overview->students->engagedInPeriod);

        $this->assertNotNull($overview->bookings);
        $this->assertSame(1, $overview->bookings->total);
    }

    public function test_executive_requires_its_own_permission(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ViewExecutiveReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(AuthorizationException::class);
        $this->reports->executiveOverview($admin, $this->period(), $this->filters());
    }

    public function test_financial_groups_stay_null_and_unqueried_without_their_permissions(): void
    {
        $admin = $this->manager();
        foreach (['ViewPaymentReports', 'ViewWalletReports', 'ViewInstructorCompensationReports'] as $permission) {
            Role::findByName('manager', 'web')->revokePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::enableQueryLog();
        $overview = $this->reports->executiveOverview($admin, $this->period(), $this->filters());
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertNull($overview->payments);
        $this->assertNull($overview->wallet);
        $this->assertNull($overview->refunds);
        $this->assertNull($overview->instructorFinancials);

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('booking_payments', $sql, 'Restricted financial sections must not query at all.');
            $this->assertStringNotContainsString('wallet_ledger_entries', $sql);
            $this->assertStringNotContainsString('instructor_earnings', $sql);
        }
    }

    public function test_executive_permission_alone_never_exposes_compensation(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ViewInstructorCompensationReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $overview = $this->reports->executiveOverview($admin, $this->period(), $this->filters());

        $this->assertNull($overview->instructorFinancials);
        $this->assertNotNull($overview->payments, 'Other finance groups keep their own permissions.');
    }

    public function test_no_revenue_margin_ltv_retention_or_utilization_kpi_exists(): void
    {
        $registry = app(MetricRegistryInterface::class);

        foreach (['recognized_revenue', 'net_revenue', 'platform_margin', 'student_lifetime_value', 'retention_rate', 'churn_rate', 'instructor_utilization_rate'] as $key) {
            $this->assertNull($registry->find($key));
        }

        // The executive DTO graph carries no such property either.
        $properties = array_map(
            fn (\ReflectionProperty $p) => strtolower($p->getName()),
            (new \ReflectionClass(ExecutiveKpiOverviewData::class))->getProperties(),
        );

        foreach (['revenue', 'margin', 'ltv', 'retention', 'churn', 'utilization', 'score', 'ranking'] as $forbidden) {
            foreach ($properties as $property) {
                $this->assertStringNotContainsString($forbidden, $property);
            }
        }
    }

    // ── Marketplace permissions ───────────────────────────────────────────

    public function test_marketplace_requires_its_own_permission(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ViewMarketplaceReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($this->reports->canViewMarketplace($admin));
        $this->assertTrue($this->reports->canViewExecutive($admin), 'The two permissions are independent.');

        $this->expectException(AuthorizationException::class);
        $this->reports->marketplaceSupply($admin, $this->period(), $this->filters());
    }

    // ── Zero side effects & performance ───────────────────────────────────

    public function test_rendering_marketplace_and_executive_mutates_nothing(): void
    {
        Http::fake();
        $admin = $this->manager();
        $student = $this->student();
        $instructor = $this->instructor();
        $this->availability($instructor);
        $this->assignSubject($instructor, $this->subject());
        $this->booking($student, $instructor, $this->subject());

        $before = [
            DB::table('bookings')->orderBy('id')->get(['id', 'status'])->toJson(),
            DB::table('teacher_availability')->count(),
            DB::table('teacher_subjects')->count(),
            DB::table('user_profiles')->orderBy('id')->get(['id', 'instructor_status'])->toJson(),
        ];
        $auditBefore = DB::table('activity_log')->count();
        $jobsBefore = DB::table('jobs')->count();

        $this->reports->marketplaceSupply($admin, $this->period(), $this->filters());
        $this->reports->marketplaceDemand($admin, $this->period(), $this->filters());
        $this->reports->marketplaceComparison($admin, $this->period(), $this->filters());
        $this->reports->executiveOverview($admin, $this->period(), $this->filters());

        $after = [
            DB::table('bookings')->orderBy('id')->get(['id', 'status'])->toJson(),
            DB::table('teacher_availability')->count(),
            DB::table('teacher_subjects')->count(),
            DB::table('user_profiles')->orderBy('id')->get(['id', 'instructor_status'])->toJson(),
        ];

        $this->assertSame($before, $after);
        $this->assertSame($auditBefore, DB::table('activity_log')->count());
        $this->assertSame($jobsBefore, DB::table('jobs')->count());
        Http::assertNothingSent();
    }

    public function test_marketplace_query_count_is_bounded(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        $instructor = $this->instructor();
        $this->assignSubject($instructor, $this->subject());
        $this->booking($student, $instructor, $this->subject());

        DB::enableQueryLog();
        $this->reports->marketplaceSupply($admin, $this->period(), $this->filters());
        $this->reports->marketplaceDemand($admin, $this->period(), $this->filters());
        $this->reports->marketplaceComparison($admin, $this->period(), $this->filters());
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(30, $count, 'Marketplace sections must stay a bounded set of grouped queries.');
    }
}
