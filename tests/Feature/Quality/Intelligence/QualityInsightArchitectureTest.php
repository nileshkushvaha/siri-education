<?php

declare(strict_types=1);

namespace Tests\Feature\Quality\Intelligence;

use App\Ai\Contracts\AiExecutionServiceInterface;
use App\Ai\Contracts\AiTaskInputResolverInterface;
use App\Ai\Contracts\AiTaskResultHandlerInterface;
use App\Quality\Intelligence\Resolvers\QualityInsightInputResolver;
use App\Quality\Intelligence\Resolvers\QualityInsightResultHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P1 must reuse the P0 foundation rather than grow a second AI path,
 * and must stay incapable of acting on its own output.
 */
class QualityInsightArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private const string DOMAIN = 'Quality/Intelligence';

    // ── Reuses the foundation ─────────────────────────────────────────

    public function test_the_domain_never_names_a_provider_or_the_execution_implementation(): void
    {
        foreach ($this->domainFiles() as $path => $code) {
            $this->assertStringNotContainsString('App\\Ai\\Providers', $code, "Must not name an AI provider: {$path}");
            $this->assertStringNotContainsString('OpenAi', $code, "Must not reference OpenAI: {$path}");
            $this->assertStringNotContainsString('App\\Ai\\Services\\AiExecutionService;', $code, "Depend on the execution CONTRACT, not the class: {$path}");
        }
    }

    public function test_the_domain_never_calls_an_http_client_or_an_ai_sdk(): void
    {
        foreach ($this->domainFiles() as $path => $code) {
            $this->assertDoesNotMatchRegularExpression('/\bHttp::/', $code, "AI calls belong behind the AI layer: {$path}");
            $this->assertStringNotContainsString('api.openai.com', $code, $path);
        }
    }

    /** Generation must go through the shared job — never a bespoke one, never inline. */
    public function test_the_domain_uses_the_shared_ai_job_and_no_job_of_its_own(): void
    {
        $service = file_get_contents(app_path('Quality/Intelligence/Services/QualityInsightService.php'));

        $this->assertStringContainsString('ExecuteAiTaskJob::dispatch', $service);
        $this->assertFalse(is_dir(app_path('Quality/Intelligence/Jobs')), 'P1 must not add its own AI job class.');

        foreach ($this->domainFiles() as $path => $code) {
            $this->assertStringNotContainsString('->execute(', str_replace('$this->execute(', '', $code), "Only ExecuteAiTaskJob may call the execution service: {$path}");
        }
    }

    public function test_the_bridge_classes_implement_the_foundation_contracts(): void
    {
        $this->assertInstanceOf(AiTaskInputResolverInterface::class, app(QualityInsightInputResolver::class));
        $this->assertInstanceOf(AiTaskResultHandlerInterface::class, app(QualityInsightResultHandler::class));
        $this->assertInstanceOf(AiExecutionServiceInterface::class, app(AiExecutionServiceInterface::class));
    }

    public function test_no_model_name_is_hardcoded_in_the_domain(): void
    {
        foreach ($this->domainFiles() as $path => $code) {
            foreach (['gpt-4', 'gpt-5', 'claude-', 'gemini-'] as $needle) {
                $this->assertStringNotContainsString($needle, $code, "Models are configured as roles in AiSettings: {$path}");
            }
        }
    }

    // ── Cannot act ────────────────────────────────────────────────────

    /**
     * The core safety property of P1: an insight can influence a human,
     * and nothing else. If this test ever fails, an AI opinion has been
     * wired into an operational or financial pathway.
     */
    public function test_the_domain_cannot_reach_financial_lifecycle_or_alerting_services(): void
    {
        $forbidden = [
            'App\\Wallet', 'App\\Payments', 'App\\Earnings', 'App\\Compliance',
            'InstructorQualityAlertService', 'InstructorStatus', 'SuspiciousActivityFlag',
            'Notification::', 'Notifiable',
        ];

        foreach ($this->domainFiles() as $path => $code) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $code, "An AI insight must never reach {$needle}: {$path}");
            }
        }
    }

    /** Nothing may read a stored insight to make a decision — it exists to be read by a person. */
    public function test_nothing_outside_the_admin_surface_reads_the_insight_model(): void
    {
        $allowed = [
            app_path('Models/AiQualityInsight.php'),
            app_path('Policies/AiQualityInsightPolicy.php'),
            app_path('Providers/QualityServiceProvider.php'),
            // Navigation metadata only — it names the admin resource so
            // the sidebar can place it, and reads no insight data.
            app_path('Filament/Navigation/NavigationRegistry.php'),
        ];

        foreach ($this->phpFilesIn([app_path()]) as $path => $code) {
            if (str_starts_with($path, app_path('Quality/Intelligence'))
                || str_starts_with($path, app_path('Filament/Resources/AiQualityInsights'))
                || in_array($path, $allowed, true)) {
                continue;
            }

            $this->assertStringNotContainsString('AiQualityInsight', $code, "Insights are read by people, not by code: {$path}");
        }
    }

    public function test_the_insight_table_carries_no_score_or_rank_a_future_feature_could_act_on(): void
    {
        $columns = Schema::getColumnListing('ai_quality_insights');

        foreach (['score', 'rank', 'rating', 'severity', 'grade', 'action'] as $forbidden) {
            foreach ($columns as $column) {
                $this->assertStringNotContainsString($forbidden, $column, "An advisory insight must carry no {$forbidden}: {$column}");
            }
        }
    }

    public function test_the_model_exposes_no_mutator_for_ai_content_outside_the_service(): void
    {
        $model = file_get_contents(app_path('Models/AiQualityInsight.php'));

        // No lifecycle hooks, no observers, no events — an insight row
        // must never trigger anything when it is written.
        $this->assertStringNotContainsString('static::created', $model);
        $this->assertStringNotContainsString('dispatchesEvents', $model);
        $this->assertStringNotContainsString('LogsActivity', $model);
    }

    /** @return array<string, string> */
    private function domainFiles(): array
    {
        return $this->phpFilesIn([app_path(self::DOMAIN)]);
    }

    /**
     * @param  list<string>  $directories
     * @return array<string, string>
     */
    private function phpFilesIn(array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }
}
