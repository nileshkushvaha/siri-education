<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Guards the Phase 23P boundary: advanced performance insights extend
 * the existing Phase 23O InstructorAnalyticsService/InstructorAnalyticsData
 * — no parallel InstructorPerformanceService/InstructorScore domain, no
 * new analytics table, no writes, no earnings duplication, no AI/
 * prediction classes, no private fields exposed, same page/route/nav
 * item as Phase 23O.
 */
final class Phase23PArchitectureTest extends TestCase
{
    public function test_no_duplicate_performance_service_or_score_domain_was_created(): void
    {
        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorPerformanceService.php'));
        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorGrowthService.php'));
        $this->assertFileDoesNotExist(app_path('Models/InstructorScore.php'));
        $this->assertFileDoesNotExist(app_path('Models/InstructorInsight.php'));
        $this->assertFileDoesNotExist(app_path('Models/InstructorMetric.php'));

        $migrations = glob(database_path('migrations/*.php'));
        $newTables = array_filter(
            $migrations,
            fn (string $file): bool => (bool) preg_match(
                '/create_(instructor_scores|instructor_insights|instructor_metrics)_table/',
                strtolower(basename($file)),
            ),
        );
        $this->assertCount(0, $newTables);
    }

    public function test_trend_methods_extend_the_existing_phase_23o_service(): void
    {
        $this->assertFileExists(app_path('Services/Instructor/InstructorAnalyticsService.php'));

        $service = file_get_contents(app_path('Services/Instructor/InstructorAnalyticsService.php'));
        $this->assertIsString($service);

        $this->assertStringContainsString('public function lessonTrends(', $service);
        $this->assertStringContainsString('public function qualityTrends(', $service);
        $this->assertStringContainsString('public function studentEngagement(', $service);
        $this->assertStringContainsString('public function performanceInsights(', $service);

        // Trend methods reuse the existing single-period aggregate,
        // never a second lesson-counting query shape.
        $this->assertStringContainsString('$this->lessonsSummary(', $service);
    }

    public function test_no_ai_prediction_or_scoring_classes_exist(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorAnalyticsService.php'));
        $this->assertIsString($service);

        foreach (['predict', 'forecast', 'score(', 'ranking', 'recommend', 'MachineLearning', 'OpenAI', 'Claude'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $service);
        }

        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorRankingService.php'));
        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorRecommendationService.php'));
    }

    public function test_analytics_service_still_never_writes(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorAnalyticsService.php'));
        $this->assertIsString($service);

        $this->assertStringNotContainsString('->save()', $service);
        $this->assertStringNotContainsString('::create(', $service);
        $this->assertStringNotContainsString('->update(', $service);
        $this->assertStringNotContainsString('->delete(', $service);
        $this->assertStringNotContainsString('::dispatch(', $service);
    }

    public function test_quality_trend_reuses_the_authoritative_eligibility_predicate(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorAnalyticsService.php'));
        $this->assertIsString($service);

        // The exact Phase 17K predicate, never a re-implemented rule.
        $this->assertStringContainsString('ReviewContributionEligibility::qualifies(', $service);
        $this->assertStringNotContainsString('class ReviewContributionEligibility', $service);
    }

    public function test_no_earnings_duplication(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorAnalyticsService.php'));
        $dto = file_get_contents(app_path('DTOs/Instructor/InstructorPerformanceInsightsData.php'));

        foreach ([$service, $dto] as $source) {
            $this->assertIsString($source);
            $this->assertStringNotContainsString('InstructorEarning', $source);
            $this->assertStringNotContainsString('earning_amount_minor', $source);
        }
    }

    public function test_no_private_or_payment_fields_are_selected_or_rendered(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorAnalyticsService.php'));
        $dtos = [
            file_get_contents(app_path('DTOs/Instructor/LessonTrendData.php')),
            file_get_contents(app_path('DTOs/Instructor/QualityTrendData.php')),
            file_get_contents(app_path('DTOs/Instructor/StudentEngagementData.php')),
            file_get_contents(app_path('DTOs/Instructor/InstructorPerformanceInsightsData.php')),
        ];
        $view = file_get_contents(resource_path('views/livewire/frontend/instructor/analytics-overview.blade.php'));

        foreach ([$service, ...$dtos, $view] as $source) {
            $this->assertIsString($source);
            $lower = strtolower($source);
            $this->assertStringNotContainsString('email', $lower);
            $this->assertStringNotContainsString('phone', $lower);
            $this->assertStringNotContainsString('wallet', $lower);
            $this->assertStringNotContainsString('payment', $lower);
        }

        // The engagement DTO carries no student identity property —
        // only aggregate counts (checked separately: a doc comment is
        // allowed to explain the omission; a `$studentName`-shaped
        // property declaration is not).
        $engagementDto = $dtos[2];
        $this->assertStringNotContainsString('public string $studentName', $engagementDto);
        $this->assertStringNotContainsString('public int $studentId', $engagementDto);
    }

    public function test_no_new_navigation_item_or_route_was_added_for_insights(): void
    {
        $menu = file_get_contents(app_path('Services/Account/AccountMenuService.php'));
        $this->assertIsString($menu);

        // Still exactly one "Analytics" nav entry — insights live on the
        // same page, not a new "Advanced Insights" menu item.
        $this->assertSame(1, substr_count($menu, "'dashboard.instructor.analytics'"));
        $this->assertStringNotContainsString('Advanced Insights', $menu);

        $routes = file_get_contents(base_path('routes/web.php'));
        $this->assertIsString($routes);
        $this->assertSame(1, substr_count($routes, "->name('instructor.analytics')"));
    }

    public function test_livewire_component_is_still_read_only_after_the_extension(): void
    {
        $component = file_get_contents(app_path('Livewire/Frontend/Instructor/AnalyticsOverview.php'));
        $this->assertIsString($component);

        $this->assertStringNotContainsString('->save()', $component);
        $this->assertStringNotContainsString('::create(', $component);
        $this->assertStringNotContainsString('->update(', $component);
        $this->assertStringContainsString('performanceInsights(', $component);
    }
}
