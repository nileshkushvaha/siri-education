<?php

declare(strict_types=1);

namespace Tests\Feature\Lessons\Summaries;

use App\Ai\Contracts\AiTaskInputResolverInterface;
use App\Ai\Contracts\AiTaskResultHandlerInterface;
use App\Lessons\Summaries\Resolvers\LessonSummaryInputResolver;
use App\Lessons\Summaries\Resolvers\LessonSummaryResultHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P3 must reuse the P0 foundation, must never touch lesson or progress
 * state, and must never reach recording data.
 */
class LessonSummaryArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private const string DOMAIN = 'Lessons/Summaries';

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
        $service = (string) file_get_contents(app_path('Lessons/Summaries/Services/LessonSummaryService.php'));

        $this->assertStringContainsString('ExecuteAiTaskJob::dispatch', $service);
        $this->assertFalse(is_dir(app_path('Lessons/Summaries/Jobs')), 'P3 must not add its own AI job class.');
    }

    public function test_the_bridge_classes_implement_the_foundation_contracts(): void
    {
        $this->assertInstanceOf(AiTaskInputResolverInterface::class, app(LessonSummaryInputResolver::class));
        $this->assertInstanceOf(AiTaskResultHandlerInterface::class, app(LessonSummaryResultHandler::class));
    }

    public function test_no_model_name_is_hardcoded_in_the_domain(): void
    {
        foreach ($this->domainFiles() as $path => $code) {
            foreach (['gpt-4', 'gpt-5', 'claude-', 'gemini-'] as $needle) {
                $this->assertStringNotContainsString($needle, $code, "Models are configured as roles in AiSettings: {$path}");
            }
        }
    }

    // ── Cannot decide anything ────────────────────────────────────────

    /**
     * The core safety property of P3. If this fails, an AI path has
     * gained the ability to complete a lesson or move a student's
     * progress.
     */
    public function test_no_ai_code_path_touches_lesson_lifecycle_or_learning_progress(): void
    {
        $surfaces = [
            ...$this->domainFiles(),
            app_path('Models/LessonAiSummary.php') => (string) file_get_contents(app_path('Models/LessonAiSummary.php')),
            app_path('Policies/LessonAiSummaryPolicy.php') => (string) file_get_contents(app_path('Policies/LessonAiSummaryPolicy.php')),
        ];

        $forbidden = [
            'LessonLifecycleService', 'TransitionLessonAction', 'FinalizeLessonOutcomeAction',
            'LearningPlanProgressService', 'LearningPlanMilestone::query', 'InstructorEarning',
            'LessonOutcomeService', 'LessonFinalizationService',
        ];

        foreach ($surfaces as $path => $code) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $code, "An AI summary must never reach {$needle}: {$path}");
            }
        }
    }

    /**
     * The lesson row itself is never written from the summary domain.
     *
     * Asserted as "no Lesson model is ever saved" rather than "no
     * column named X is assigned": the summary's own row legitimately
     * has a `status`, so matching column names would flag correct code
     * and teach the next person to weaken the test. What actually
     * matters is that no write ever reaches a Lesson.
     */
    public function test_the_domain_never_writes_to_a_lesson(): void
    {
        foreach ($this->domainFiles() as $path => $code) {
            foreach (['$lesson->save(', '$lesson->update(', '$lesson->fill(', '$lesson->forceFill(', 'Lesson::query()->update('] as $write) {
                $this->assertStringNotContainsString($write, $code, "Lesson state is owned by the lesson domain: {$path}");
            }

            // Columns that could only ever belong to another domain's row.
            foreach (["'completion_notes'", "'progress_percent'", "'completed_at'", "'outcome_finalized_at'"] as $column) {
                $this->assertDoesNotMatchRegularExpression(
                    '/'.preg_quote($column, '/').'\s*=>/',
                    $code,
                    "Lesson and plan state are owned elsewhere: {$path}",
                );
            }
        }
    }

    public function test_the_summary_table_carries_no_progress_or_grade_column(): void
    {
        $columns = Schema::getColumnListing('lesson_ai_summaries');

        foreach (['mastery', 'progress', 'grade', 'score', 'percent', 'rank', 'level'] as $forbidden) {
            foreach ($columns as $column) {
                $this->assertStringNotContainsString($forbidden, $column, "An advisory summary must carry no {$forbidden}: {$column}");
            }
        }
    }

    // ── No recording data, anywhere in the domain ─────────────────────

    public function test_the_domain_never_reads_recording_or_meeting_data(): void
    {
        foreach ($this->domainFiles() as $path => $code) {
            $stripped = $this->withoutComments($code);

            foreach (['Recording', 'transcript', 'Transcript', 'BookingMeeting', 'MeetingProvider'] as $needle) {
                $this->assertStringNotContainsString($needle, $stripped, "Recording intelligence is a separate phase: {$path}");
            }
        }
    }

    // ── Cannot run unasked ────────────────────────────────────────────

    /**
     * Generation must be reachable only from an explicit instructor
     * action — in particular, nothing may hang it off the existing
     * LessonCompleted event.
     */
    public function test_nothing_outside_the_instructor_path_requests_a_summary(): void
    {
        $allowed = [
            app_path('Lessons/Summaries/Services/LessonSummaryService.php'),
            app_path('Lessons/Summaries/Contracts/LessonSummaryServiceInterface.php'),
            app_path('Lessons/Summaries/Resolvers/LessonSummaryInputResolver.php'),
            app_path('Lessons/Summaries/Resolvers/LessonSummaryResultHandler.php'),
            app_path('Livewire/Frontend/Instructor/LessonFeedbackManager.php'),
            app_path('Providers/LessonServiceProvider.php'),
        ];

        foreach ($this->phpFilesIn([app_path()]) as $path => $code) {
            if (in_array($path, $allowed, true)) {
                continue;
            }

            $this->assertStringNotContainsString('LessonSummaryService', $code, "Summary generation is instructor-initiated only: {$path}");
        }
    }

    public function test_no_listener_observer_or_command_touches_lesson_summaries(): void
    {
        $directories = array_filter([
            app_path('Listeners'), app_path('Observers'), app_path('Console'), app_path('Jobs'),
        ], 'is_dir');

        foreach ($this->phpFilesIn($directories) as $path => $code) {
            $this->assertStringNotContainsString('LessonAiSummary', $code, "AI summaries must never run from a background hook: {$path}");
            $this->assertStringNotContainsString('Summaries', $code, $path);
        }
    }

    /** Not shown to students in this release — no student surface may reference one. */
    public function test_no_student_surface_references_a_summary(): void
    {
        $surfaces = array_filter([
            app_path('Livewire/Frontend/Student'),
            resource_path('views/livewire/frontend/student'),
            resource_path('views/student'),
        ], 'is_dir');

        foreach ($surfaces as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $code = (string) file_get_contents($file->getPathname());

                $this->assertStringNotContainsString('aiSummary', $code, "Students do not see AI summaries in this release: {$file->getPathname()}");
                $this->assertStringNotContainsString('LessonAiSummary', $code, $file->getPathname());
            }
        }
    }

    private function withoutComments(string $code): string
    {
        $out = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
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
