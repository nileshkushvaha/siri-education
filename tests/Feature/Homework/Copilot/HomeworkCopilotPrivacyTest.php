<?php

declare(strict_types=1);

namespace Tests\Feature\Homework\Copilot;

use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Exceptions\AiException;
use App\Ai\Jobs\ExecuteAiTaskJob;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Homework\Copilot\Contracts\HomeworkFeedbackDraftServiceInterface;
use App\Homework\Copilot\Resolvers\HomeworkCopilotInputResolver;
use App\Homework\Copilot\Services\HomeworkCopilotInputBuilder;
use App\Models\HomeworkAiFeedbackDraft;
use App\Models\HomeworkAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Homework\Copilot\Concerns\BuildsCopilotFixtures;
use Tests\TestCase;

/**
 * What may leave the platform for one homework submission, and what
 * must not.
 *
 * Asserted against the REAL prompt variables the resolver produces —
 * the same strings a provider would receive — so a future change that
 * adds a field to the prompt is caught here rather than in review.
 */
class HomeworkCopilotPrivacyTest extends TestCase
{
    use BuildsCopilotFixtures, RefreshDatabase;

    /** @return array<string, string> the exact variables that would be sent */
    private function promptVariables(HomeworkAiFeedbackDraft $draft): array
    {
        return app(HomeworkCopilotInputResolver::class)->resolve(new AiTaskDescriptor(
            feature: AiFeature::HomeworkAssistant,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'homework_feedback',
            inputResolver: HomeworkCopilotInputResolver::class,
            correlationId: $draft->getKey(),
        ));
    }

    private function sentText(HomeworkAiFeedbackDraft $draft): string
    {
        return implode("\n", $this->promptVariables($draft));
    }

    private function requestDraft(HomeworkAssignment $assignment, User $instructor): HomeworkAiFeedbackDraft
    {
        return app(HomeworkFeedbackDraftServiceInterface::class)->request($assignment, $instructor);
    }

    public function test_participant_names_are_removed_from_the_submission(): void
    {
        $this->enableHomeworkCopilot();

        $instructor = $this->instructor('Priya', 'Nair');
        $student = $this->student('Mira', 'Kowalski');

        $assignment = $this->submittedAssignment(
            $instructor,
            $student,
            'Hi Priya, this is Mira Kowalski. I solved the equation by isolating x first.',
        );

        $sent = $this->sentText($this->requestDraft($assignment, $instructor));

        $this->assertStringNotContainsString('Mira', $sent);
        $this->assertStringNotContainsString('Kowalski', $sent);
        $this->assertStringNotContainsString('Priya', $sent);
        // The work itself survives — the point is feedback on it.
        $this->assertStringContainsString('isolating x', $sent);
    }

    public function test_contact_details_never_reach_the_prompt(): void
    {
        $this->enableHomeworkCopilot();

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment(
            $instructor,
            $this->student(),
            'My answer is attached. Email me at pupil@example.com or call 07700 900123, my handle is @miraK.',
        );

        $sent = $this->sentText($this->requestDraft($assignment, $instructor));

        $this->assertStringNotContainsString('pupil@example.com', $sent);
        $this->assertStringNotContainsString('900123', $sent);
        $this->assertStringNotContainsString('@miraK', $sent);
    }

    public function test_no_identifier_of_any_kind_reaches_the_prompt(): void
    {
        $this->enableHomeworkCopilot();

        $instructor = $this->instructor();
        $student = $this->student();
        $assignment = $this->submittedAssignment($instructor, $student);

        $draft = $this->requestDraft($assignment, $instructor);
        $sent = $this->sentText($draft);

        $this->assertStringNotContainsString((string) $student->id, $sent);
        $this->assertStringNotContainsString((string) $student->email, $sent);
        $this->assertStringNotContainsString($assignment->getKey(), $sent);
        $this->assertStringNotContainsString((string) $assignment->booking_id, $sent);
        $this->assertStringNotContainsString($draft->getKey(), $sent);
    }

    /** Grades and prior feedback are assessment, and the model must never see them. */
    public function test_grades_and_existing_feedback_are_never_sent(): void
    {
        $this->enableHomeworkCopilot();

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student(), overrides: [
            'grade' => 'B+',
            'feedback' => 'Previously told them to slow down.',
        ]);

        $sent = $this->sentText($this->requestDraft($assignment, $instructor));

        $this->assertStringNotContainsString('B+', $sent);
        $this->assertStringNotContainsString('slow down', $sent);
    }

    public function test_instructor_authored_fields_are_redacted_too(): void
    {
        $this->enableHomeworkCopilot();

        $instructor = $this->instructor();
        $student = $this->student('Mira', 'Kowalski');

        $assignment = $this->submittedAssignment($instructor, $student, overrides: [
            // Instructors routinely name the student in a brief.
            'description' => 'Mira, focus on showing your working this week.',
        ]);

        $sent = $this->sentText($this->requestDraft($assignment, $instructor));

        $this->assertStringNotContainsString('Mira', $sent);
        $this->assertStringContainsString('showing your working', $sent);
    }

    /** A hard ceiling on how much student writing can leave in one request. */
    public function test_a_long_submission_is_truncated_and_the_model_is_told(): void
    {
        $this->enableHomeworkCopilot();

        $instructor = $this->instructor();
        $long = str_repeat('This is a sentence of my essay. ', 400);

        $assignment = $this->submittedAssignment($instructor, $this->student(), $long);

        $variables = $this->promptVariables($this->requestDraft($assignment, $instructor));

        $this->assertLessThanOrEqual(
            HomeworkCopilotInputBuilder::MAX_SUBMISSION_CHARACTERS + 10,
            mb_strlen($variables['submission']),
        );
        $this->assertStringContainsString('extract', $variables['submission_note']);
    }

    /** Attachment BYTES are out of scope: no OCR, no file upload to a provider. */
    public function test_attachment_contents_are_never_sent_only_their_existence(): void
    {
        $this->enableHomeworkCopilot();

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        $variables = $this->promptVariables($this->requestDraft($assignment, $instructor));
        $sent = implode("\n", $variables);

        // No attachment on this fixture, so the note stays silent about
        // one; the builder never reads media contents in any case.
        $this->assertStringNotContainsString('base64', $sent);
        $this->assertStringNotContainsString('submission_attachments', $sent);

        $builder = file_get_contents(app_path('Homework/Copilot/Services/HomeworkCopilotInputBuilder.php'));
        $this->assertStringNotContainsString('getPath', $builder);
        $this->assertStringNotContainsString('file_get_contents', $builder);
    }

    public function test_the_stored_provenance_contains_no_submission_text(): void
    {
        $this->enableHomeworkCopilot();

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student(), 'A very distinctive sentence about parabolas.');

        $draft = $this->requestDraft($assignment, $instructor);
        $this->promptVariables($draft);

        $snapshot = json_encode($draft->refresh()->source_snapshot, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('distinctive sentence', $snapshot);
        $this->assertStringNotContainsString('parabolas', $snapshot);
        $this->assertStringContainsString('submission_characters', $snapshot);
    }

    public function test_the_queued_payload_carries_no_submission_content(): void
    {
        $this->enableHomeworkCopilot();

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student(), 'A very distinctive sentence about parabolas.');

        $draft = $this->requestDraft($assignment, $instructor);

        $payload = serialize(new ExecuteAiTaskJob(new AiTaskDescriptor(
            feature: AiFeature::HomeworkAssistant,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'homework_feedback',
            inputResolver: HomeworkCopilotInputResolver::class,
            correlationId: $draft->getKey(),
        )));

        $this->assertStringNotContainsString('distinctive sentence', $payload);
        $this->assertStringNotContainsString('parabolas', $payload);
    }

    /** The submission is read at execution time, so a withdrawn or reviewed assignment is never sent. */
    public function test_work_no_longer_awaiting_review_is_not_sent_when_the_job_runs(): void
    {
        $this->enableHomeworkCopilot();

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        $draft = $this->requestDraft($assignment, $instructor);

        // The instructor finished reviewing while the job waited.
        app(HomeworkServiceInterface::class)->review($assignment, 'My own feedback.', null);

        $this->expectException(AiException::class);

        $this->promptVariables($draft->refresh());
    }
}
