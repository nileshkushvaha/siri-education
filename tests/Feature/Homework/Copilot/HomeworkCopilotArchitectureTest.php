<?php

declare(strict_types=1);

namespace Tests\Feature\Homework\Copilot;

use App\Ai\Contracts\AiTaskInputResolverInterface;
use App\Ai\Contracts\AiTaskResultHandlerInterface;
use App\Homework\Copilot\Resolvers\HomeworkCopilotInputResolver;
use App\Homework\Copilot\Resolvers\HomeworkFeedbackResultHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P2 must reuse the P0 foundation, and must stay incapable of grading,
 * publishing, or running without an instructor asking.
 */
class HomeworkCopilotArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private const string DOMAIN = 'Homework/Copilot';

    // ── Reuses the foundation ─────────────────────────────────────────

    public function test_the_domain_never_names_a_provider_or_calls_http(): void
    {
        foreach ($this->domainFiles() as $path => $code) {
            $this->assertStringNotContainsString('App\\Ai\\Providers', $code, "Must not name an AI provider: {$path}");
            $this->assertStringNotContainsString('OpenAi', $code, "Must not reference OpenAI: {$path}");
            $this->assertStringNotContainsString('api.openai.com', $code, $path);
            $this->assertDoesNotMatchRegularExpression('/\bHttp::/', $code, "AI calls belong behind the AI layer: {$path}");
        }
    }

    public function test_the_domain_uses_the_shared_ai_job_and_no_job_of_its_own(): void
    {
        $service = file_get_contents(app_path('Homework/Copilot/Services/HomeworkFeedbackDraftService.php'));

        $this->assertStringContainsString('ExecuteAiTaskJob::dispatch', $service);
        $this->assertFalse(is_dir(app_path('Homework/Copilot/Jobs')), 'P2 must not add its own AI job class.');
    }

    public function test_the_bridge_classes_implement_the_foundation_contracts(): void
    {
        $this->assertInstanceOf(AiTaskInputResolverInterface::class, app(HomeworkCopilotInputResolver::class));
        $this->assertInstanceOf(AiTaskResultHandlerInterface::class, app(HomeworkFeedbackResultHandler::class));
    }

    public function test_no_model_name_is_hardcoded_in_the_domain(): void
    {
        foreach ($this->domainFiles() as $path => $code) {
            foreach (['gpt-4', 'gpt-5', 'claude-', 'gemini-'] as $needle) {
                $this->assertStringNotContainsString($needle, $code, "Models are configured as roles in AiSettings: {$path}");
            }
        }
    }

    // ── Cannot grade ──────────────────────────────────────────────────

    /**
     * The core safety property of P2. If this fails, an AI code path has
     * gained the ability to assess a student.
     */
    public function test_no_ai_code_path_writes_a_grade_or_the_published_feedback(): void
    {
        $surfaces = [
            ...$this->domainFiles(),
            app_path('Models/HomeworkAiFeedbackDraft.php') => (string) file_get_contents(app_path('Models/HomeworkAiFeedbackDraft.php')),
            app_path('Policies/HomeworkAiFeedbackDraftPolicy.php') => (string) file_get_contents(app_path('Policies/HomeworkAiFeedbackDraftPolicy.php')),
        ];

        foreach ($surfaces as $path => $code) {
            // Neither the graded status nor the assignment's own
            // feedback/grade columns may be written from here.
            $this->assertStringNotContainsString('HomeworkStatus::Graded', str_replace(
                ['$assignment->status === HomeworkStatus::Graded', 'if ($assignment->status === HomeworkStatus::Graded)'],
                '',
                $code,
            ), "AI code must never set a homework to Graded: {$path}");

            $this->assertDoesNotMatchRegularExpression("/'grade'\s*=>/", $code, "AI code must never write a grade: {$path}");
            $this->assertDoesNotMatchRegularExpression("/'feedback'\s*=>/", $code, "AI code must never write published feedback: {$path}");
            $this->assertStringNotContainsString('ReviewHomeworkAction', $code, "Publishing stays with the instructor flow: {$path}");
        }
    }

    public function test_the_draft_table_carries_no_grade_score_or_pass_column(): void
    {
        $columns = Schema::getColumnListing('homework_ai_feedback_drafts');

        foreach (['score', 'grade', 'mark', 'percent', 'pass', 'rank', 'correct'] as $forbidden) {
            foreach ($columns as $column) {
                $this->assertStringNotContainsString($forbidden, $column, "An advisory draft must carry no {$forbidden}: {$column}");
            }
        }
    }

    /** The published feedback column must never be written by anything in the AI path. */
    public function test_published_feedback_is_written_only_by_the_instructor_review_action(): void
    {
        $writers = [];

        foreach ($this->phpFilesIn([app_path()]) as $path => $code) {
            if (preg_match("/'feedback'\s*=>/", $code) === 1) {
                $writers[] = str_replace(app_path().'/', '', $path);
            }
        }

        sort($writers);

        $this->assertSame(['Homework/Actions/ReviewHomeworkAction.php'], $writers);
    }

    // ── Cannot run unasked ────────────────────────────────────────────

    /**
     * Generation must be reachable only from an explicit instructor
     * action. No listener, no scheduled command, no observer may
     * dispatch it — that is what keeps a student's work from being sent
     * to a provider in the background.
     */
    public function test_nothing_outside_the_instructor_path_requests_a_draft(): void
    {
        $allowed = [
            app_path('Homework/Copilot/Services/HomeworkFeedbackDraftService.php'),
            // The one caller: the instructor's own review screen.
            app_path('Livewire/Frontend/Instructor/HomeworkList.php'),
            // Container wiring only — it binds the service, never calls it.
            app_path('Providers/HomeworkServiceProvider.php'),
            // The result handler receives an answer; it cannot request one.
            app_path('Homework/Copilot/Resolvers/HomeworkFeedbackResultHandler.php'),
            app_path('Homework/Copilot/Resolvers/HomeworkCopilotInputResolver.php'),
            app_path('Homework/Copilot/Contracts/HomeworkFeedbackDraftServiceInterface.php'),
        ];

        foreach ($this->phpFilesIn([app_path()]) as $path => $code) {
            if (in_array($path, $allowed, true)) {
                continue;
            }

            // ->request( is the only entry point that spends money and
            // sends student work; nothing else in the application may
            // reach it.
            $this->assertStringNotContainsString('HomeworkFeedbackDraftService', $code, "Draft generation is instructor-initiated only: {$path}");
        }
    }

    public function test_no_listener_observer_or_command_touches_the_copilot(): void
    {
        $directories = array_filter([
            app_path('Listeners'), app_path('Observers'), app_path('Console'), app_path('Jobs'),
        ], 'is_dir');

        foreach ($this->phpFilesIn($directories) as $path => $code) {
            $this->assertStringNotContainsString('Copilot', $code, "AI drafting must never run from a background hook: {$path}");
            $this->assertStringNotContainsString('HomeworkAiFeedbackDraft', $code, $path);
        }
    }

    /** A draft is never shown to a student — no student surface may reference it. */
    public function test_no_student_surface_references_a_draft(): void
    {
        $studentSurfaces = array_filter([
            app_path('Livewire/Frontend/Student'),
            resource_path('views/livewire/frontend/student'),
            resource_path('views/student'),
        ], 'is_dir');

        foreach ($studentSurfaces as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $code = (string) file_get_contents($file->getPathname());

                $this->assertStringNotContainsString('aiDraft', $code, "Students never see AI drafts: {$file->getPathname()}");
                $this->assertStringNotContainsString('HomeworkAiFeedbackDraft', $code, $file->getPathname());
            }
        }
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
