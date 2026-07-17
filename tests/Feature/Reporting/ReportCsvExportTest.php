<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanStatus;
use App\Models\AcademicCategory;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use App\Reporting\Contracts\LearningAnalyticsReportServiceInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Exceptions\ReportExportException;
use App\Reporting\Exports\ReportCsvExporter;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 18I — CSV export: layered authorization, owner-equality of
 * rows, formula-injection neutralization, sensitive-identity masking,
 * row limits with no partial output, and the shared-reference audit
 * lifecycle.
 */
class ReportCsvExportTest extends TestCase
{
    use RefreshDatabase;

    private ReportCsvExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->exporter = app(ReportCsvExporter::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function manager(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    private function revoke(string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            Role::findByName('manager', 'web')->revokePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function period(): ReportingPeriod
    {
        return ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, 'UTC');
    }

    private function filters(): ReportFilters
    {
        return new ReportFilters(period: $this->period());
    }

    /** @param array<string, mixed> $overrides */
    private function plan(string $studentFirstName = 'Priya', array $overrides = []): StudentLearningPlan
    {
        $student = User::factory()->create(['status' => 'active', 'first_name' => $studentFirstName, 'last_name' => 'Sharma']);
        $student->assignRole('student');

        $goal = StudentLearningGoal::query()->create([
            'user_id' => $student->id,
            'subject_id' => Subject::query()->firstOrCreate(
                ['slug' => 'maths'],
                ['academic_category_id' => AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General'])->id, 'name' => 'Maths', 'status' => 'active'],
            )->id,
            'title' => 'Goal',
            'type' => 'academic',
            'status' => LearningGoalStatus::Active,
        ]);

        return StudentLearningPlan::query()->create(array_merge([
            'student_user_id' => $student->id,
            'learning_goal_id' => $goal->id,
            'subject_id' => $goal->subject_id,
            'title' => 'Plan',
            'status' => LearningPlanStatus::Active,
            'progress_percent' => 10,
            'started_at' => now()->subDay(),
        ], $overrides));
    }

    private function csvBody(User $user, string $exportKey): string
    {
        $response = $this->exporter->download($user, $exportKey, $this->period(), $this->filters());

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    // ── Authorization (§4) ────────────────────────────────────────────────

    public function test_view_permission_alone_cannot_export(): void
    {
        $admin = $this->manager();
        $this->revoke('ExportReports');

        $this->assertFalse($this->exporter->canExport($admin, 'learning_plan_review_rows'));

        $this->expectException(AuthorizationException::class);
        $this->exporter->download($admin, 'learning_plan_review_rows', $this->period(), $this->filters());
    }

    public function test_general_export_cannot_export_financial_datasets(): void
    {
        $admin = $this->manager();
        $this->revoke('ExportFinancialReports');

        // General export permission still present…
        $this->assertTrue($this->exporter->canExport($admin, 'student_engagement_rows'));
        // …but financial datasets stay closed.
        $this->assertFalse($this->exporter->canExport($admin, 'wallet_refund_linkage_rows'));

        $this->expectException(AuthorizationException::class);
        $this->exporter->download($admin, 'wallet_refund_linkage_rows', $this->period(), $this->filters());
    }

    public function test_export_permission_without_view_permission_cannot_export(): void
    {
        $admin = $this->manager();
        $this->revoke('ViewLearningReports');

        $this->assertFalse($this->exporter->canExport($admin, 'learning_plan_review_rows'), 'Sensitive/general export never grants report-view access.');

        $this->expectException(AuthorizationException::class);
        $this->exporter->download($admin, 'learning_plan_review_rows', $this->period(), $this->filters());
    }

    public function test_unauthorized_export_executes_zero_report_queries(): void
    {
        $admin = $this->manager();
        $this->revoke('ExportReports');
        $this->plan();

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->exporter->download($admin, 'learning_plan_review_rows', $this->period(), $this->filters());
            $this->fail('Expected authorization exception.');
        } catch (AuthorizationException) {
            // expected
        }

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('student_learning_plans', $sql, 'Denied exports must never touch report tables.');
        }
    }

    public function test_unknown_export_key_is_denied(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->exporter->download($this->manager(), 'nonexistent_dataset', $this->period(), $this->filters());
    }

    // ── Correctness (§11) ─────────────────────────────────────────────────

    public function test_export_rows_match_the_source_report_service(): void
    {
        $admin = $this->manager();
        $this->plan();

        $serviceRows = app(LearningAnalyticsReportServiceInterface::class)
            ->planReviewTable($admin, $this->period(), $this->filters(), ReportCsvExporter::MAX_ROWS + 1);

        $body = $this->csvBody($admin, 'learning_plan_review_rows');
        $lines = array_values(array_filter(explode("\n", trim($body))));

        // preamble header + preamble values + data header + N rows
        $this->assertCount(3 + $serviceRows->total(), $lines);
        $this->assertStringContainsString('plan_id,student_label,instructor_label', $lines[2]);
        $this->assertStringContainsString((string) $serviceRows->items()[0]->planId, $lines[3]);
        $this->assertStringContainsString('Active', $lines[3], 'Row values come from the owning service unchanged.');
    }

    public function test_csv_contains_provenance_preamble_with_audit_reference(): void
    {
        $admin = $this->manager();
        $this->plan();

        $body = $this->csvBody($admin, 'learning_plan_review_rows');

        $this->assertStringContainsString('export_key,report_key,generated_at,reporting_timezone,period_start,period_end_exclusive,audit_reference,row_count', $body);
        $this->assertStringContainsString('learning_plan_review_rows,learning_progress,', $body);
        $this->assertStringContainsString('UTC', $body);

        // The CSV's audit reference matches the recorded audit events.
        $reference = DB::table('activity_log')
            ->where('log_name', 'reporting')
            ->orderByDesc('id')
            ->value('properties');
        $this->assertStringContainsString(json_decode((string) $reference, true)['correlation_reference'], $body);
    }

    // ── Security (§6) ─────────────────────────────────────────────────────

    public function test_formula_injection_is_neutralized(): void
    {
        $admin = $this->manager();
        $this->plan('=HYPERLINK("http://evil"');

        $body = $this->csvBody($admin, 'learning_plan_review_rows');

        $this->assertStringNotContainsString("\n=HYPERLINK", $body);
        $this->assertStringContainsString("'=", $body, 'Leading = is neutralized with a quote prefix.');
    }

    public function test_full_identity_requires_sensitive_export_permission(): void
    {
        $admin = $this->manager();
        $this->revoke('ExportSensitiveReports');
        $this->plan('Priya');

        $body = $this->csvBody($admin, 'learning_plan_review_rows');

        $this->assertStringNotContainsString('Priya Sharma', $body, 'Without ExportSensitiveReports the CSV re-masks identity.');
        $this->assertStringContainsString('P***', $body);
    }

    public function test_sensitive_export_permission_reveals_identity_when_view_identity_exists(): void
    {
        $admin = $this->manager(); // manager holds both identity view + sensitive export
        $this->plan('Priya');

        $body = $this->csvBody($admin, 'learning_plan_review_rows');

        $this->assertStringContainsString('Priya Sharma', $body);
    }

    public function test_filename_is_safe(): void
    {
        $admin = $this->manager();
        $this->plan('Priya');

        $response = $this->exporter->download($admin, 'learning_plan_review_rows', $this->period(), $this->filters());
        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringContainsString('learning_plan_review_rows-', $disposition);
        $this->assertStringNotContainsString('Priya', $disposition);
        $this->assertStringNotContainsString('Sharma', $disposition);
    }

    // ── Limits & audit (§8–9) ─────────────────────────────────────────────

    public function test_over_limit_export_fails_without_partial_output_and_records_failure(): void
    {
        $admin = $this->manager();

        // Bind a fake owning service reporting more rows than the limit —
        // the exporter must reject BEFORE producing any output.
        $oversized = new LengthAwarePaginator([], ReportCsvExporter::MAX_ROWS + 10, ReportCsvExporter::MAX_ROWS + 1);
        $mock = $this->createMock(LearningAnalyticsReportServiceInterface::class);
        $mock->method('planReviewTable')->willReturn($oversized);
        app()->instance(LearningAnalyticsReportServiceInterface::class, $mock);
        $exporter = app()->make(ReportCsvExporter::class);

        $before = DB::table('activity_log')->where('log_name', 'reporting')->count();

        try {
            $exporter->download($admin, 'learning_plan_review_rows', $this->period(), $this->filters());
            $this->fail('Expected the over-limit rejection.');
        } catch (ReportExportException $e) {
            $this->assertStringContainsString('Narrow the date range or filters', $e->getMessage());
        }

        $events = DB::table('activity_log')
            ->where('log_name', 'reporting')
            ->orderBy('id')->skip($before)->take(10)->get(['event', 'properties']);

        $this->assertCount(2, $events);
        $this->assertSame('report_export_requested', $events[0]->event);
        $this->assertSame('report_export_failed', $events[1]->event);
        $this->assertSame(
            json_decode((string) $events[0]->properties, true)['correlation_reference'],
            json_decode((string) $events[1]->properties, true)['correlation_reference'],
        );
    }

    public function test_provider_failure_records_failed_event_with_shared_reference(): void
    {
        $admin = $this->manager();

        $mock = $this->createMock(LearningAnalyticsReportServiceInterface::class);
        $mock->method('planReviewTable')->willThrowException(new \RuntimeException('boom'));
        app()->instance(LearningAnalyticsReportServiceInterface::class, $mock);
        $exporter = app()->make(ReportCsvExporter::class);

        $before = DB::table('activity_log')->where('log_name', 'reporting')->count();

        try {
            $exporter->download($admin, 'learning_plan_review_rows', $this->period(), $this->filters());
            $this->fail('Expected the provider failure to propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        $events = DB::table('activity_log')
            ->where('log_name', 'reporting')
            ->orderBy('id')->skip($before)->take(10)->get(['event', 'properties']);

        $this->assertCount(2, $events);
        $this->assertSame('report_export_failed', $events[1]->event);
        $properties = json_decode((string) $events[1]->properties, true);
        $this->assertArrayNotHasKey('exception', $properties, 'No stack trace or raw error in audit metadata.');
    }

    public function test_requested_and_completed_events_share_one_reference_and_row_count(): void
    {
        $admin = $this->manager();
        $this->plan();

        $before = DB::table('activity_log')->where('log_name', 'reporting')->count();

        $this->csvBody($admin, 'learning_plan_review_rows');

        $events = DB::table('activity_log')
            ->where('log_name', 'reporting')
            ->orderBy('id')
            ->skip($before)
            ->take(10)
            ->get(['event', 'properties']);

        $this->assertCount(2, $events);
        $requested = json_decode((string) $events[0]->properties, true);
        $completed = json_decode((string) $events[1]->properties, true);

        $this->assertSame('report_export_requested', $events[0]->event);
        $this->assertSame('report_export_completed', $events[1]->event);
        $this->assertSame($requested['correlation_reference'], $completed['correlation_reference']);
        $this->assertSame(1, $completed['row_count']);
        $this->assertArrayNotHasKey('email', $requested['filters'] ?? []);
    }

    public function test_audit_metadata_contains_no_pii_or_secret(): void
    {
        $admin = $this->manager();
        $this->plan('Priya');

        $this->csvBody($admin, 'learning_plan_review_rows');

        $properties = (string) DB::table('activity_log')->where('log_name', 'reporting')->orderByDesc('id')->value('properties');

        $this->assertStringNotContainsString('Priya', $properties);
        $this->assertStringNotContainsString('Sharma', $properties);
        $this->assertStringNotContainsString('@', str_replace('generated_at', '', $properties));
    }

    // ── Zero side effects ─────────────────────────────────────────────────

    public function test_export_path_has_zero_source_domain_side_effects(): void
    {
        Http::fake();
        $admin = $this->manager();
        $this->plan();

        $plansBefore = DB::table('student_learning_plans')->orderBy('id')->get()->toJson();
        $jobsBefore = DB::table('jobs')->count();

        $this->csvBody($admin, 'learning_plan_review_rows');

        $this->assertSame($plansBefore, DB::table('student_learning_plans')->orderBy('id')->get()->toJson());
        $this->assertSame($jobsBefore, DB::table('jobs')->count(), 'No queued export exists in this phase.');
        Http::assertNothingSent();
    }

    // ── Registry consistency ──────────────────────────────────────────────

    public function test_every_export_definition_maps_to_an_export_enabled_report(): void
    {
        $registry = app(ReportRegistryInterface::class);

        foreach (ReportCsvExporter::definitions() as $definition) {
            $report = $registry->find($definition->reportKey);

            $this->assertNotNull($report, $definition->key);
            $this->assertTrue($report->exportAvailable, "{$definition->reportKey} must be flagged exportAvailable.");
            $this->assertSame($definition->exportPermission, $report->requiredExportPermission, "{$definition->reportKey} export permission must match its dataset.");
        }
    }

    public function test_reports_without_completed_exports_remain_not_exportable(): void
    {
        $registry = app(ReportRegistryInterface::class);
        $exportReportKeys = collect(ReportCsvExporter::definitions())->pluck('reportKey')->all();

        foreach ($registry->all() as $report) {
            if (! in_array($report->key, $exportReportKeys, true) && $report->key !== 'booking_lesson_kpis') {
                $this->assertFalse($report->exportAvailable, "{$report->key} has no completed export definition and must not be exportable.");
            }
        }
    }
}
