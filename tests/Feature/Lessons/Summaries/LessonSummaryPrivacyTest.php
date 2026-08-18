<?php

declare(strict_types=1);

namespace Tests\Feature\Lessons\Summaries;

use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Exceptions\AiException;
use App\Ai\Jobs\ExecuteAiTaskJob;
use App\Enums\LearningPlanStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Summaries\Contracts\LessonSummaryServiceInterface;
use App\Lessons\Summaries\Resolvers\LessonSummaryInputResolver;
use App\Lessons\Summaries\Services\LessonSummaryInputBuilder;
use App\Models\HomeworkAssignment;
use App\Models\InstructorStudentFeedback;
use App\Models\LearningPlanMilestone;
use App\Models\Lesson;
use App\Models\LessonAiSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Lessons\Summaries\Concerns\BuildsLessonSummaryFixtures;
use Tests\TestCase;

/**
 * What may leave the platform for one lesson summary, and what must
 * not — asserted against the REAL prompt variables the resolver
 * produces.
 */
class LessonSummaryPrivacyTest extends TestCase
{
    use BuildsLessonSummaryFixtures, RefreshDatabase;

    /** @return array<string, string> */
    private function promptVariables(LessonAiSummary $summary): array
    {
        return app(LessonSummaryInputResolver::class)->resolve(new AiTaskDescriptor(
            feature: AiFeature::LessonSummary,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'lesson_summary',
            inputResolver: LessonSummaryInputResolver::class,
            correlationId: $summary->getKey(),
        ));
    }

    private function sentText(LessonAiSummary $summary): string
    {
        return implode("\n", $this->promptVariables($summary));
    }

    private function request(Lesson $lesson, User $instructor): LessonAiSummary
    {
        return app(LessonSummaryServiceInterface::class)->request($lesson, $instructor);
    }

    public function test_participant_names_are_removed_from_the_instructor_notes(): void
    {
        $this->enableLessonSummaries();

        $instructor = $this->instructor('Priya', 'Nair');
        $student = $this->student('Mira', 'Kowalski');

        $lesson = $this->completedLesson(
            $instructor,
            $student,
            'Mira Kowalski worked through factorisation with me, Priya, and grasped the method.',
        );

        $sent = $this->sentText($this->request($lesson, $instructor));

        $this->assertStringNotContainsString('Mira', $sent);
        $this->assertStringNotContainsString('Kowalski', $sent);
        $this->assertStringNotContainsString('Priya', $sent);
        $this->assertStringContainsString('factorisation', $sent);
    }

    public function test_contact_details_never_reach_the_prompt(): void
    {
        $this->enableLessonSummaries();

        $instructor = $this->instructor();
        $lesson = $this->completedLesson(
            $instructor,
            $this->student(),
            'Parent asked me to email them at parent@example.com or call 07700 900123 about next steps.',
        );

        $sent = $this->sentText($this->request($lesson, $instructor));

        $this->assertStringNotContainsString('parent@example.com', $sent);
        $this->assertStringNotContainsString('900123', $sent);
    }

    public function test_no_identifier_dates_or_money_reach_the_prompt(): void
    {
        $this->enableLessonSummaries();

        $instructor = $this->instructor();
        $student = $this->student();
        $lesson = $this->completedLesson($instructor, $student);

        $summary = $this->request($lesson, $instructor);
        $sent = $this->sentText($summary);

        $this->assertStringNotContainsString($lesson->getKey(), $sent);
        $this->assertStringNotContainsString((string) $lesson->booking_id, $sent);
        $this->assertStringNotContainsString($summary->getKey(), $sent);
        $this->assertStringNotContainsString((string) $student->id, $sent);
        $this->assertStringNotContainsString((string) $student->email, $sent);
        // Dates are identifiers; the duration is the useful part.
        $this->assertStringNotContainsString($lesson->starts_at->toDateString(), $sent);
    }

    /**
     * The private instructor-to-student feedback record is deliberately
     * excluded — it is an assessment of a child's character gathered for
     * a different purpose.
     */
    public function test_private_instructor_feedback_is_never_sent(): void
    {
        $this->enableLessonSummaries();

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());

        InstructorStudentFeedback::query()->create([
            'lesson_id' => $lesson->getKey(),
            'booking_id' => $lesson->booking_id,
            'student_id' => $lesson->student_id,
            'instructor_id' => $instructor->id,
            'outcome_snapshot' => 'completed',
            'source_outcome_version' => 1,
            'engagement_observation' => 'Seemed distracted and unwilling to try.',
            'private_notes' => 'Suspect trouble at home.',
            'submitted_at' => now(),
            'version' => 1,
        ]);

        $sent = $this->sentText($this->request($lesson, $instructor));

        $this->assertStringNotContainsString('distracted', $sent);
        $this->assertStringNotContainsString('trouble at home', $sent);
    }

    /** Recording data is a separate phase with its own consent decisions. */
    public function test_the_builder_never_touches_recording_data(): void
    {
        // Comments are stripped first: the class docblock legitimately
        // explains WHY recordings are excluded, and asserting on prose
        // would forbid documenting the rule.
        $code = $this->codeWithoutComments(app_path('Lessons/Summaries/Services/LessonSummaryInputBuilder.php'));

        foreach (['Recording', 'transcript', 'meeting', 'Meeting', 'media', 'Media'] as $needle) {
            $this->assertStringNotContainsString($needle, $code, 'Lesson summaries must never read recording or meeting data.');
        }
    }

    private function codeWithoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    public function test_homework_titles_are_sent_but_never_submissions_or_grades(): void
    {
        $this->enableLessonSummaries();

        $instructor = $this->instructor();
        $student = $this->student();
        $lesson = $this->completedLesson($instructor, $student);

        HomeworkAssignment::factory()->create([
            'booking_id' => $lesson->booking_id,
            'teacher_id' => $instructor->id,
            'student_id' => $student->id,
            'title' => 'Quadratics exercises 1-5',
            'description' => 'Complete and show working.',
            'submission_text' => 'My answer to question one is x equals four.',
            'grade' => 'B+',
            'feedback' => 'Careless in places.',
        ]);

        $sent = $this->sentText($this->request($lesson, $instructor));

        $this->assertStringContainsString('Quadratics exercises 1-5', $sent);
        $this->assertStringNotContainsString('x equals four', $sent);
        $this->assertStringNotContainsString('B+', $sent);
        $this->assertStringNotContainsString('Careless', $sent);
    }

    public function test_an_archived_plans_context_is_not_sent(): void
    {
        $this->enableLessonSummaries();

        $instructor = $this->instructor();
        $student = $this->student();
        $plan = $this->activePlanFor($instructor, $student, 'Stale archived focus');
        $plan->update(['status' => LearningPlanStatus::Archived]);

        $lesson = $this->completedLesson($instructor, $student, overrides: ['learning_plan_id' => $plan->id]);

        $sent = $this->sentText($this->request($lesson, $instructor));

        $this->assertStringNotContainsString('Stale archived focus', $sent);
    }

    public function test_an_active_plans_focus_and_objectives_are_sent(): void
    {
        $this->enableLessonSummaries();

        $instructor = $this->instructor();
        $student = $this->student();
        $plan = $this->activePlanFor($instructor, $student, 'Building confidence with algebra');

        LearningPlanMilestone::query()->create([
            'learning_plan_id' => $plan->id,
            'title' => 'Factorise quadratics independently',
            'status' => 'pending',
            'sort_order' => 1,
            'created_by' => $instructor->id,
            'updated_by' => $instructor->id,
        ]);

        $lesson = $this->completedLesson($instructor, $student, overrides: ['learning_plan_id' => $plan->id]);

        $variables = $this->promptVariables($this->request($lesson, $instructor));

        $this->assertStringContainsString('Building confidence with algebra', $variables['plan_focus']);
        $this->assertStringContainsString('Factorise quadratics independently', $variables['plan_objectives']);
    }

    public function test_a_long_instructor_note_is_capped(): void
    {
        $this->enableLessonSummaries();

        $instructor = $this->instructor();
        // The longest note the platform itself accepts
        // (lessons.completion_notes is varchar 1000).
        $lesson = $this->completedLesson($instructor, $this->student(), str_repeat('a', 1000));

        $variables = $this->promptVariables($this->request($lesson, $instructor));

        $this->assertLessThanOrEqual(
            LessonSummaryInputBuilder::MAX_NOTE_CHARACTERS,
            mb_strlen($variables['instructor_notes']),
        );
    }

    public function test_the_stored_provenance_contains_no_lesson_content(): void
    {
        $this->enableLessonSummaries();

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student(), 'A very distinctive note about parabolas.');

        $summary = $this->request($lesson, $instructor);
        $this->promptVariables($summary);

        $snapshot = json_encode($summary->refresh()->source_snapshot, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('distinctive note', $snapshot);
        $this->assertStringNotContainsString('parabolas', $snapshot);
        $this->assertStringContainsString('instructor_notes_present', $snapshot);
    }

    public function test_the_queued_payload_carries_no_lesson_content(): void
    {
        $this->enableLessonSummaries();

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student(), 'A very distinctive note about parabolas.');

        $summary = $this->request($lesson, $instructor);

        $payload = serialize(new ExecuteAiTaskJob(new AiTaskDescriptor(
            feature: AiFeature::LessonSummary,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'lesson_summary',
            inputResolver: LessonSummaryInputResolver::class,
            correlationId: $summary->getKey(),
        )));

        $this->assertStringNotContainsString('distinctive note', $payload);
        $this->assertStringNotContainsString('parabolas', $payload);
    }

    public function test_a_lesson_no_longer_completed_is_not_sent_when_the_job_runs(): void
    {
        $this->enableLessonSummaries();

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());
        $summary = $this->request($lesson, $instructor);

        // The outcome fell into dispute while the job waited.
        $lesson->forceFill(['outcome' => LessonOutcome::TechnicalIssue])->save();

        $this->expectException(AiException::class);

        $this->promptVariables($summary->refresh());
    }
}
