<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Reporting\Contracts\MetricRegistryInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\Exports\ReportCsvExporter;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 18J — closure invariants for the whole Phase 18 reporting
 * catalogue. These assertions are the machine-readable form of the
 * closure audit: placeholder-free owners, seeded permissions, honest
 * unavailability, export-state consistency and the formal
 * `meeting_reliability` decision.
 */
class Phase18ClosureAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
    }

    public function test_no_available_metric_has_a_placeholder_calculation_owner(): void
    {
        foreach (app(MetricRegistryInterface::class)->all() as $metric) {
            foreach (['GAP', 'TODO', 'Future', 'Unknown', 'Not implemented'] as $placeholder) {
                $this->assertStringNotContainsString(
                    $placeholder,
                    $metric->calculationOwner,
                    "Metric '{$metric->key}' has a placeholder calculation owner.",
                );
            }
        }
    }

    public function test_every_metric_calculation_owner_names_a_real_class_and_method(): void
    {
        $namespaces = [
            'App\Reporting\Services\\', 'App\Reporting\Repositories\\', 'App\Reporting\Support\\',
            'App\Services\Student\\', 'App\Services\\', 'App\Quality\Repositories\\',
            'App\Homework\Services\\', 'App\Booking\Services\\', 'App\Booking\Repositories\\',
        ];

        foreach (app(MetricRegistryInterface::class)->all() as $metric) {
            if (! preg_match('/([A-Za-z]+)::([A-Za-z]+)/', $metric->calculationOwner, $m)) {
                $this->fail("Metric '{$metric->key}' owner '{$metric->calculationOwner}' names no Class::method.");
            }

            [, $class, $method] = $m;
            $resolved = null;

            foreach ($namespaces as $namespace) {
                if (class_exists($namespace.$class)) {
                    $resolved = $namespace.$class;
                    break;
                }
            }

            $this->assertNotNull($resolved, "Metric '{$metric->key}' owner class '{$class}' does not exist.");
            $this->assertTrue(
                method_exists($resolved, $method),
                "Metric '{$metric->key}' owner {$resolved}::{$method} does not exist.",
            );
        }
    }

    public function test_every_registry_permission_is_actually_seeded(): void
    {
        $seeded = Permission::query()->pluck('name')->all();

        foreach (app(ReportRegistryInterface::class)->all() as $report) {
            $this->assertContains($report->requiredViewPermission, $seeded, "Report '{$report->key}' view permission is unseeded.");

            if ($report->requiredExportPermission !== null) {
                $this->assertContains($report->requiredExportPermission, $seeded, "Report '{$report->key}' export permission is unseeded.");
            }
        }

        foreach (app(MetricRegistryInterface::class)->all() as $metric) {
            $this->assertContains($metric->requiredPermission, $seeded, "Metric '{$metric->key}' permission is unseeded.");
        }
    }

    public function test_meeting_reliability_is_the_only_remaining_placeholder_and_is_honest(): void
    {
        $unavailable = array_values(array_filter(
            app(ReportRegistryInterface::class)->all(),
            fn ($report) => ! $report->available,
        ));

        $this->assertCount(1, $unavailable, 'Exactly one formally-unavailable definition may remain at closure.');
        $this->assertSame('meeting_reliability', $unavailable[0]->key);
        $this->assertNull($unavailable[0]->routeName, 'An unavailable report must have no route.');
        $this->assertFalse($unavailable[0]->exportAvailable, 'An unavailable report must not be exportable.');
        $this->assertStringContainsString(
            'unavailable',
            strtolower($unavailable[0]->description),
            'The closure decision must be documented on the definition itself.',
        );
    }

    public function test_export_state_is_consistent_between_registry_and_export_definitions(): void
    {
        $registry = app(ReportRegistryInterface::class);
        $exportsByReport = [];

        foreach (ReportCsvExporter::definitions() as $definition) {
            $exportsByReport[$definition->reportKey][] = $definition;

            $report = $registry->find($definition->reportKey);
            $this->assertNotNull($report, "Export '{$definition->key}' references unknown report '{$definition->reportKey}'.");
            $this->assertTrue($report->available, "Export '{$definition->key}' backs an unavailable report.");
            $this->assertTrue($report->exportAvailable, "Report '{$definition->reportKey}' must be flagged exportAvailable.");
            $this->assertSame($report->requiredExportPermission, $definition->exportPermission, "Export permission mismatch for '{$definition->key}'.");

            if ($definition->financial) {
                $this->assertSame('ExportFinancialReports', $definition->exportPermission, "Financial dataset '{$definition->key}' must require the financial export permission.");
            }
        }

        foreach ($registry->all() as $report) {
            if ($report->exportAvailable && $report->key !== 'booking_lesson_kpis') {
                $this->assertArrayHasKey($report->key, $exportsByReport, "Report '{$report->key}' is flagged exportable but has no dataset.");
            }
        }
    }

    public function test_no_show_definitions_share_one_timestamp_basis_across_phases(): void
    {
        // 18C owns the metric; 18D surfaces the same fact. Both must count
        // finalized lesson outcomes on half-open outcome_finalized_at bounds.
        foreach (['student_no_shows', 'instructor_no_shows'] as $key) {
            $metric = app(MetricRegistryInterface::class)->find($key);
            $this->assertNotNull($metric);
            $this->assertStringContainsString('outcome_finalized_at', $metric->timestampField);
        }

        foreach ([
            'app/Reporting/Repositories/LessonOperationsRepository.php',
            'app/Reporting/Repositories/InstructorPerformanceRepository.php',
        ] as $path) {
            $contents = (string) file_get_contents(base_path($path));
            $this->assertStringContainsString('outcome_finalized_at', $contents, "{$path} must use the shared finalized-outcome basis.");
            $this->assertStringNotContainsString("'>=', \$period->start)", $contents, 'Period bounds must be the UTC half-open pair, never local start.');
        }
    }

    public function test_no_forbidden_fabricated_metric_exists_in_the_final_catalogue(): void
    {
        $registry = app(MetricRegistryInterface::class);

        foreach ([
            'recognized_revenue', 'net_revenue', 'platform_margin', 'student_lifetime_value',
            'retention_rate', 'instructor_utilization', 'marketplace_health_score', 'instructor_ranking',
            'referral_conversion_rate', 'notification_delivery_rate', 'curriculum_progress',
            'churn_prediction', 'predicted_completion',
        ] as $forbidden) {
            $this->assertNull($registry->find($forbidden), "Fabricated metric '{$forbidden}' must not exist at closure.");
        }
    }
}
