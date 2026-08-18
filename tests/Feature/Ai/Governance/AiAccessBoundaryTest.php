<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Governance;

use App\Ai\Contracts\AiSchemaInterface;
use App\Ai\Contracts\AiSchemaRegistryInterface;
use App\Ai\Schemas\AiSchemaRegistry;
use App\Ai\Schemas\ConnectivityCheckSchema;
use App\Homework\Copilot\Schemas\HomeworkFeedbackSchema;
use App\Lessons\Summaries\Schemas\LessonSummarySchema;
use App\Messaging\Safety\Schemas\CommunicationRiskSchema;
use App\Quality\Intelligence\Schemas\QualityInsightSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The boundary between "AI is a controlled platform capability" and
 * "AI is a personal assistant". These assertions are what stop the
 * second thing appearing later without a decision.
 */
class AiAccessBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** No route may expose AI to anyone, under any role. */
    public function test_no_http_route_exposes_ai(): void
    {
        foreach (Route::getRoutes() as $route) {
            $uri = strtolower($route->uri());

            foreach (['ai/chat', 'ai/prompt', 'ai/ask', 'ai/query', 'assistant', 'copilot/chat'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $uri, "A general AI endpoint exists: {$uri}");
            }

            $action = $route->getActionName();

            // No route may reach the execution service directly, whatever
            // it is called.
            $this->assertStringNotContainsString('AiExecutionService', $action, "A route calls the AI execution service: {$uri}");
        }
    }

    /**
     * Only the shared job may execute AI. Every other caller must go
     * through a domain service that applies policy first.
     */
    public function test_only_the_shared_job_calls_the_execution_service(): void
    {
        $callers = [];

        foreach ($this->phpFilesIn([app_path()]) as $path => $code) {
            // ->execute( on the execution service, however it is named.
            if (preg_match('/AiExecutionServiceInterface\s+\$\w+/', $code) === 1) {
                $callers[] = str_replace(app_path().'/', '', $path);
            }
        }

        sort($callers);

        // AiExecutionService is the implementation, not a caller — it
        // never takes the interface as a dependency.
        $this->assertSame(
            ['Ai/Jobs/ExecuteAiTaskJob.php'],
            $callers,
            'Only ExecuteAiTaskJob may execute AI; everything else goes through a domain service.',
        );
    }

    /** No controller, Livewire component or command may dispatch AI work. */
    public function test_only_domain_services_dispatch_ai_work(): void
    {
        $dispatchers = [];

        foreach ($this->phpFilesIn([app_path()]) as $path => $code) {
            if (str_contains($code, 'ExecuteAiTaskJob::dispatch')) {
                $dispatchers[] = str_replace(app_path().'/', '', $path);
            }
        }

        sort($dispatchers);

        $this->assertSame([
            'Homework/Copilot/Services/HomeworkFeedbackDraftService.php',
            'Lessons/Summaries/Services/LessonSummaryService.php',
            'Messaging/Safety/Services/MessageSafetyService.php',
            'Quality/Intelligence/Services/QualityInsightService.php',
        ], $dispatchers);
    }

    /**
     * No schema anywhere may carry a field that instructs the platform
     * to act. This is what makes prompt injection structurally
     * ineffective rather than a matter of the model complying.
     */
    public function test_no_ai_schema_carries_an_action_field(): void
    {
        $forbidden = ['block', 'ban', 'suspend', 'restrict', 'delete', 'remove', 'approve', 'grade', 'score', 'mark', 'pass', 'action', 'execute'];

        foreach (app(AiSchemaRegistryInterface::class) instanceof AiSchemaRegistry ? $this->registeredSchemas() : [] as $schema) {
            foreach (array_keys($schema->jsonSchema()['properties'] ?? []) as $property) {
                foreach ($forbidden as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        strtolower((string) $property),
                        sprintf('Schema "%s" exposes an action-shaped field "%s".', $schema->key(), $property),
                    );
                }
            }
        }
    }

    /** The AI module never reaches the database except for its own telemetry. */
    public function test_the_ai_module_never_queries_business_data(): void
    {
        foreach ($this->phpFilesIn([app_path('Ai')]) as $path => $code) {
            // The one permitted exception is its own run repository.
            if (str_ends_with($path, 'Repositories/AiRunRepository.php')) {
                continue;
            }

            foreach (['DB::table', 'DB::select', 'DB::statement', 'whereRaw', 'selectRaw'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "The AI module must receive data from resolvers, never query: {$path}");
            }

            // Matched on the facade import rather than the bare token:
            // a class named "…Schema::KEY" is not schema inspection.
            $this->assertStringNotContainsString(
                'use Illuminate\\Support\\Facades\\Schema;',
                $code,
                "The AI module must never inspect the schema: {$path}",
            );
        }
    }

    public function test_the_ai_module_never_touches_the_environment_or_filesystem(): void
    {
        foreach ($this->phpFilesIn([app_path('Ai')]) as $path => $code) {
            foreach (['env(', 'getenv(', 'file_get_contents', 'file_put_contents', 'fopen(', 'shell_exec', 'exec(', 'eval('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "Unexpected runtime access in the AI module: {$path}");
            }
        }
    }

    /** Container resolution from a payload happens in exactly two guarded places. */
    public function test_dynamic_container_resolution_is_confined_to_the_job(): void
    {
        $resolvers = [];

        foreach ($this->phpFilesIn([app_path('Ai')]) as $path => $code) {
            if (preg_match('/app\(\$/', $code) === 1) {
                $resolvers[] = str_replace(app_path().'/', '', $path);
            }
        }

        $this->assertSame(['Ai/Jobs/ExecuteAiTaskJob.php'], $resolvers);
    }

    /** @return list<AiSchemaInterface> */
    private function registeredSchemas(): array
    {
        return [
            new ConnectivityCheckSchema,
            new QualityInsightSchema,
            new HomeworkFeedbackSchema,
            new LessonSummarySchema,
            new CommunicationRiskSchema,
        ];
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
