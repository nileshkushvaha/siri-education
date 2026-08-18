<?php

declare(strict_types=1);

namespace App\Homework\Copilot\Services;

use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Jobs\ExecuteAiTaskJob;
use App\Ai\Services\AiBudgetGuard;
use App\Ai\Services\AiFeatureGate;
use App\Homework\Copilot\Contracts\HomeworkFeedbackDraftRepositoryInterface;
use App\Homework\Copilot\Contracts\HomeworkFeedbackDraftServiceInterface;
use App\Homework\Copilot\DTOs\HomeworkFeedbackDraftData;
use App\Homework\Copilot\Enums\HomeworkFeedbackDraftStatus;
use App\Homework\Copilot\Exceptions\HomeworkCopilotException;
use App\Homework\Copilot\Prompts\HomeworkFeedbackPrompt;
use App\Homework\Copilot\Resolvers\HomeworkCopilotInputResolver;
use App\Homework\Copilot\Resolvers\HomeworkFeedbackResultHandler;
use App\Homework\Enums\HomeworkStatus;
use App\Models\HomeworkAiFeedbackDraft;
use App\Models\HomeworkAssignment;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of homework_ai_feedback_drafts.
 *
 * GENERATION IS ALWAYS EXPLICITLY INSTRUCTOR-INITIATED. There is no
 * event listener, no scheduled sweep, no on-submission hook and no bulk
 * path anywhere in this phase — a student's work is only ever sent to a
 * provider because the tutor who can already read it asked, one
 * submission at a time. That is the primary protection for content
 * that redaction cannot fully anonymize, so it is enforced here rather
 * than left to convention: this method takes the acting instructor and
 * re-checks the assignment's own review authorization.
 *
 * It also never writes to the assignment. Publishing feedback stays
 * entirely with HomeworkService::review(), from text the instructor
 * typed.
 */
final class HomeworkFeedbackDraftService implements HomeworkFeedbackDraftServiceInterface
{
    public function __construct(
        private readonly HomeworkFeedbackDraftRepositoryInterface $drafts,
        private readonly AiFeatureGate $gate,
        private readonly AiBudgetGuard $budget,
        private readonly AuditTrailService $audit,
    ) {}

    public function request(HomeworkAssignment $assignment, User $instructor): HomeworkAiFeedbackDraft
    {
        // The same gate as publishing feedback: only the assigning
        // instructor. Re-checked here rather than trusted from the UI,
        // because this is the boundary where student work leaves the
        // platform.
        if ($instructor->id !== $assignment->teacher_id) {
            throw new AuthorizationException('Only the assigning instructor can request an AI feedback draft.');
        }

        if ($assignment->status === HomeworkStatus::Graded) {
            throw HomeworkCopilotException::alreadyReviewed();
        }

        if ($assignment->status !== HomeworkStatus::Submitted) {
            throw HomeworkCopilotException::notSubmitted();
        }

        $blocked = $this->gate->blockReason(AiFeature::HomeworkAssistant) ?? $this->budget->blockReason();

        if ($blocked !== null) {
            throw HomeworkCopilotException::aiUnavailable($blocked);
        }

        if ($this->drafts->pendingFor($assignment) !== null) {
            throw HomeworkCopilotException::alreadyGenerating();
        }

        $draft = DB::transaction(function () use ($assignment, $instructor): HomeworkAiFeedbackDraft {
            $draft = $this->drafts->create([
                'homework_assignment_id' => $assignment->getKey(),
                'requested_by' => $instructor->id,
                'status' => HomeworkFeedbackDraftStatus::Pending,
                'prompt_key' => HomeworkFeedbackPrompt::KEY,
                'prompt_version' => HomeworkFeedbackPrompt::VERSION,
                'requires_instructor_review' => true,
            ]);

            // Auditable because this is the moment a student's work
            // becomes eligible to leave the platform — who asked, for
            // which submission, when.
            $this->audit->logUser(
                $instructor,
                'homework_ai_copilot',
                'homework_ai_draft_requested',
                'AI homework feedback draft requested',
                $draft,
                ['homework_assignment_id' => $assignment->getKey()],
            );

            return $draft;
        });

        ExecuteAiTaskJob::dispatch(new AiTaskDescriptor(
            feature: AiFeature::HomeworkAssistant,
            capability: AiCapability::StructuredGeneration,
            promptKey: HomeworkFeedbackPrompt::KEY,
            inputResolver: HomeworkCopilotInputResolver::class,
            resultHandler: HomeworkFeedbackResultHandler::class,
            correlationId: $draft->getKey(),
            promptVersion: HomeworkFeedbackPrompt::VERSION,
            subjectType: $assignment->getMorphClass(),
            subjectId: (string) $assignment->getKey(),
            requestedBy: $instructor->id,
        ))->afterCommit();

        return $draft;
    }

    public function storeResult(HomeworkAiFeedbackDraft $draft, HomeworkFeedbackDraftData $data, ?string $aiRunId): HomeworkAiFeedbackDraft
    {
        if ($draft->status->isTerminal()) {
            // A released-and-rerun job may deliver twice; the first
            // answer stands.
            return $draft;
        }

        return $this->drafts->update($draft, [
            'ai_run_id' => $aiRunId,
            'status' => HomeworkFeedbackDraftStatus::Ready,
            'failure_code' => null,
            'summary' => $data->summary,
            'strengths' => $data->strengths,
            'improvements' => $data->improvements,
            'suggested_feedback' => $data->suggestedFeedback,
            'confidence' => $data->confidence,
            'requires_instructor_review' => true,
        ]);
    }

    public function markFailed(HomeworkAiFeedbackDraft $draft, ?string $failureCode, ?string $aiRunId = null): HomeworkAiFeedbackDraft
    {
        if ($draft->status->isTerminal()) {
            return $draft;
        }

        return $this->drafts->update($draft, [
            'status' => HomeworkFeedbackDraftStatus::Failed,
            'failure_code' => $failureCode,
            'ai_run_id' => $aiRunId ?: $draft->ai_run_id,
        ]);
    }

    public function markUsed(HomeworkAiFeedbackDraft $draft, User $instructor): HomeworkAiFeedbackDraft
    {
        $this->assertOwner($draft, $instructor);

        if (! $draft->status->isActionable()) {
            return $draft;
        }

        return $this->drafts->update($draft, [
            'status' => HomeworkFeedbackDraftStatus::Used,
            'used_at' => now(),
        ]);
    }

    public function discard(HomeworkAiFeedbackDraft $draft, User $instructor): HomeworkAiFeedbackDraft
    {
        $this->assertOwner($draft, $instructor);

        if ($draft->status === HomeworkFeedbackDraftStatus::Used) {
            return $draft;
        }

        return $this->drafts->update($draft, [
            'status' => HomeworkFeedbackDraftStatus::Discarded,
            'discarded_at' => now(),
        ]);
    }

    /** Records the text-free provenance of what the model was shown. */
    public function recordProvenance(HomeworkAiFeedbackDraft $draft, array $provenance): HomeworkAiFeedbackDraft
    {
        return $this->drafts->update($draft, ['source_snapshot' => $provenance]);
    }

    private function assertOwner(HomeworkAiFeedbackDraft $draft, User $instructor): void
    {
        if ($instructor->id !== $draft->assignment?->teacher_id) {
            throw new AuthorizationException('Only the assigning instructor can act on this draft.');
        }
    }
}
