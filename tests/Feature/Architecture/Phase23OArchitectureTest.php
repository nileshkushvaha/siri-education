<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Guards the instructor-analytics-foundation boundary: the instructor analytics foundation
 * is a pure read-aggregation orchestrator across existing domains
 * (Lesson, Homework, Reviews, student roster) — no new analytics
 * table/model, no writes, no dashboard duplication, no private fields
 * exposed, and every metric traces back to a repository/service, never
 * a raw collection counted in PHP.
 */
final class Phase23OArchitectureTest extends TestCase
{
    public function test_no_duplicate_analytics_domain_was_created(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/InstructorAnalytics.php'));
        $this->assertFileDoesNotExist(app_path('Models/AnalyticsMetric.php'));
        $this->assertFileDoesNotExist(app_path('Models/InstructorPerformanceMetric.php'));
        $this->assertFileDoesNotExist(app_path('Models/InstructorStats.php'));
        $this->assertFileDoesNotExist(app_path('Models/InstructorKPI.php'));

        $migrations = glob(database_path('migrations/*.php'));
        $newAnalyticsMigrations = array_filter(
            $migrations,
            fn (string $file): bool => (bool) preg_match(
                '/create_(instructor_analytics|analytics_metrics|instructor_stats|instructor_kpis)_table/',
                strtolower(basename($file)),
            ),
        );
        $this->assertCount(0, $newAnalyticsMigrations);
    }

    public function test_analytics_service_never_writes(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorAnalyticsService.php'));
        $this->assertIsString($service);

        $this->assertStringNotContainsString('->save()', $service);
        $this->assertStringNotContainsString('::create(', $service);
        $this->assertStringNotContainsString('->update(', $service);
        $this->assertStringNotContainsString('->delete(', $service);
        $this->assertStringNotContainsString('event(', $service);
        $this->assertStringNotContainsString('::dispatch(', $service);
    }

    public function test_analytics_service_reuses_existing_domain_services_not_dashboard_service(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorAnalyticsService.php'));
        $this->assertIsString($service);

        // Combines existing sources — never InstructorDashboardService,
        // which owns dashboard widgets, a different responsibility.
        $this->assertStringContainsString('InstructorStudentService', $service);
        $this->assertStringContainsString('HomeworkRepositoryInterface', $service);
        $this->assertStringContainsString('InstructorQualityInsightsServiceInterface', $service);
        $this->assertStringNotContainsString('InstructorDashboardService', $service);

        // The rating aggregate is reused unchanged, never recalculated.
        $this->assertStringContainsString('->ratingSummary', $service);
        $this->assertStringNotContainsString('InstructorRatingAggregate::', $service);
    }

    public function test_livewire_component_is_read_only(): void
    {
        $component = file_get_contents(app_path('Livewire/Frontend/Instructor/AnalyticsOverview.php'));
        $this->assertIsString($component);

        $this->assertStringNotContainsString('->save()', $component);
        $this->assertStringNotContainsString('::create(', $component);
        $this->assertStringNotContainsString('->update(', $component);
        $this->assertStringNotContainsString('->delete(', $component);

        // setPeriod() only ever selects which aggregate queries run.
        $this->assertStringContainsString('public function setPeriod(', $component);
        $this->assertStringContainsString('tryFrom(', $component);
    }

    public function test_no_private_or_payment_fields_are_selected_or_rendered(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorAnalyticsService.php'));
        $dto = file_get_contents(app_path('DTOs/Instructor/InstructorAnalyticsData.php'));
        $view = file_get_contents(resource_path('views/livewire/frontend/instructor/analytics-overview.blade.php'));

        foreach ([$service, $dto, $view] as $source) {
            $this->assertIsString($source);
            $lower = strtolower($source);
            $this->assertStringNotContainsString('email', $lower);
            $this->assertStringNotContainsString('phone', $lower);
            $this->assertStringNotContainsString('wallet', $lower);
            $this->assertStringNotContainsString('payment', $lower);
            $this->assertStringNotContainsString('commission', $lower);
        }
    }

    public function test_period_boundaries_reuse_the_existing_reporting_period_value_object(): void
    {
        $enum = file_get_contents(app_path('Enums/InstructorAnalyticsPeriod.php'));
        $this->assertIsString($enum);

        $this->assertStringContainsString('ReportingPeriod::forPreset', $enum);
        $this->assertStringContainsString('ReportingPeriod::custom', $enum);
        $this->assertStringContainsString('ReportingTimezoneResolver::resolve', $enum);

        // No second date-boundary implementation (e.g. a bespoke
        // Carbon-only period calculator duplicating ReportingPeriod).
        $this->assertStringNotContainsString('class ReportingPeriod', $enum);
    }

    public function test_metrics_are_aggregate_queries_never_a_materialized_collection_then_counted(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorAnalyticsService.php'));
        $this->assertIsString($service);

        $this->assertStringContainsString('selectRaw', $service);
        $this->assertStringNotContainsString('->get()->count()', $service);
        $this->assertStringNotContainsString('->all())->count()', $service);

        $studentService = file_get_contents(app_path('Services/Instructor/InstructorStudentService.php'));
        $this->assertIsString($studentService);
        $this->assertStringContainsString('function totalCount', $studentService);
        $this->assertStringContainsString('function activeCount', $studentService);
        $this->assertStringContainsString('function newCount', $studentService);

        $homeworkRepository = file_get_contents(app_path('Homework/Repositories/HomeworkRepository.php'));
        $this->assertIsString($homeworkRepository);
        $this->assertStringContainsString('function statsForTeacher', $homeworkRepository);
    }

    public function test_navigation_adds_performance_group_without_breaking_existing_teach_items(): void
    {
        $menu = file_get_contents(app_path('Services/Account/AccountMenuService.php'));
        $this->assertIsString($menu);

        $this->assertStringContainsString("'Performance', 'items'", $menu);
        $this->assertStringContainsString("'Analytics', 'dashboard.instructor.analytics'", $menu);
        $this->assertStringContainsString("'Reviews & Quality', 'dashboard.instructor.quality-insights'", $menu);
        $this->assertStringContainsString("'Students', 'dashboard.instructor.students'", $menu);
        $this->assertStringContainsString("'Homework', 'dashboard.instructor.homework'", $menu);
    }
}
