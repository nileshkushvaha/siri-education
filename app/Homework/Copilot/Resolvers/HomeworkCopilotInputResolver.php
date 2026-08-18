<?php

declare(strict_types=1);

namespace App\Homework\Copilot\Resolvers;

use App\Ai\Contracts\AiTaskInputResolverInterface;
use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\Enums\AiFailureCode;
use App\Ai\Exceptions\AiException;
use App\Homework\Copilot\Contracts\HomeworkFeedbackDraftRepositoryInterface;
use App\Homework\Copilot\DTOs\HomeworkCopilotInput;
use App\Homework\Copilot\Services\HomeworkCopilotInputBuilder;
use App\Homework\Copilot\Services\HomeworkFeedbackDraftService;
use App\Homework\Enums\HomeworkStatus;
use App\Models\HomeworkAiFeedbackDraft;

/**
 * Turns a queued descriptor back into prompt variables — the moment the
 * student's submission is read, and the only place it is rendered for a
 * model.
 *
 * Reading happens HERE rather than at dispatch (see AiTaskDescriptor):
 * a queue payload is a durable database row, and a student's homework
 * must not live in one. A consequence worth stating: if the instructor
 * publishes their feedback, or the work is withdrawn, between clicking
 * Generate and the job running, the run is abandoned rather than
 * completed against stale content.
 */
final class HomeworkCopilotInputResolver implements AiTaskInputResolverInterface
{
    public function __construct(
        private readonly HomeworkFeedbackDraftRepositoryInterface $drafts,
        private readonly HomeworkCopilotInputBuilder $builder,
        private readonly HomeworkFeedbackDraftService $service,
    ) {}

    public function resolve(AiTaskDescriptor $descriptor): array
    {
        $draft = $this->draft($descriptor);
        $assignment = $draft->assignment;

        if ($assignment === null) {
            throw new AiException('The homework this draft belongs to no longer exists.', AiFailureCode::NotConfigured);
        }

        // Re-checked at execution time, not just at request time: the
        // instructor may have finished reviewing while the job waited,
        // and sending the work to a provider after that would be a
        // transfer nobody currently wants.
        if ($assignment->status !== HomeworkStatus::Submitted) {
            throw new AiException('This homework is no longer awaiting review.', AiFailureCode::NotConfigured);
        }

        $input = $this->builder->build($assignment);

        if ($input->isTooSparse()) {
            throw new AiException('There is not enough submitted work to draft feedback from.', AiFailureCode::NotConfigured);
        }

        // Provenance is written before the call, so even a failed run
        // records what would have been sent.
        $this->service->recordProvenance($draft, $input->toProvenance());

        return [
            'subject' => $input->subjectLabel,
            'academic_level' => $input->academicLevelLabel,
            'assignment_title' => $input->assignmentTitle,
            'assignment_brief' => $input->assignmentBrief ?? 'No written brief was provided.',
            'submission_note' => $this->submissionNote($input),
            'submission' => (string) $input->submissionText,
        ];
    }

    private function draft(AiTaskDescriptor $descriptor): HomeworkAiFeedbackDraft
    {
        $draft = $descriptor->correlationId === null
            ? null
            : $this->drafts->find($descriptor->correlationId);

        if ($draft === null) {
            throw new AiException('The feedback draft this run belongs to no longer exists.', AiFailureCode::NotConfigured);
        }

        return $draft;
    }

    /**
     * Tells the model exactly what it is and is not looking at. A model
     * that believes it has the whole submission will confidently draft
     * feedback about a conclusion it never saw.
     */
    private function submissionNote(HomeworkCopilotInput $input): string
    {
        $parts = [];

        $parts[] = $input->wasTruncated
            ? sprintf('an extract — the first %d of %d characters', HomeworkCopilotInputBuilder::MAX_SUBMISSION_CHARACTERS, $input->originalSubmissionCharacters)
            : 'the complete written submission';

        if ($input->hasAttachment) {
            $parts[] = 'the student also attached a file which you have NOT been given — tell the tutor to review it directly';
        }

        return implode('; ', $parts);
    }
}
