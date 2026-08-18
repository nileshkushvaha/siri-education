<?php

declare(strict_types=1);

namespace App\Lessons\Summaries\Resolvers;

use App\Ai\Contracts\AiTaskInputResolverInterface;
use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\Enums\AiFailureCode;
use App\Ai\Exceptions\AiException;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Summaries\Contracts\LessonSummaryRepositoryInterface;
use App\Lessons\Summaries\DTOs\LessonSummaryInput;
use App\Lessons\Summaries\Services\LessonSummaryInputBuilder;
use App\Lessons\Summaries\Services\LessonSummaryService;
use App\Models\LessonAiSummary;

/**
 * Turns a queued descriptor back into prompt variables — the moment the
 * lesson's context is read, and the only place it is rendered for a
 * model.
 *
 * Reading happens HERE rather than at dispatch: a queue payload is a
 * durable database row and must carry identifiers only. It also means
 * the outcome is re-checked at execution time, so a lesson that fell
 * into dispute while the job waited is never summarised.
 */
final class LessonSummaryInputResolver implements AiTaskInputResolverInterface
{
    public function __construct(
        private readonly LessonSummaryRepositoryInterface $summaries,
        private readonly LessonSummaryInputBuilder $builder,
        private readonly LessonSummaryService $service,
    ) {}

    public function resolve(AiTaskDescriptor $descriptor): array
    {
        $summary = $this->summary($descriptor);
        $lesson = $summary->lesson;

        if ($lesson === null) {
            throw new AiException('The lesson this summary belongs to no longer exists.', AiFailureCode::NotConfigured);
        }

        if ($lesson->outcome !== LessonOutcome::Completed) {
            throw new AiException('This lesson is no longer a completed lesson.', AiFailureCode::NotConfigured);
        }

        $input = $this->builder->build($lesson);

        if ($input->isTooSparse()) {
            // Refusing costs nothing. Asking a model to summarize a
            // lesson it knows nothing about is how invented detail ends
            // up in a student's record.
            throw new AiException('There is not enough recorded detail to summarize this lesson.', AiFailureCode::NotConfigured);
        }

        $this->service->recordProvenance($summary, $input->toProvenance());

        return [
            'subject' => $input->subjectLabel,
            'academic_level' => $input->academicLevelLabel,
            'topic' => $this->topic($input),
            'duration' => (string) $input->durationMinutes,
            'plan_focus' => $input->planFocus ?? 'No plan focus recorded.',
            'plan_objectives' => $this->bulletList($input->planObjectives, 'No open objectives recorded.'),
            'instructor_notes' => $input->instructorNotes ?? 'The tutor did not leave a note for this lesson.',
            'homework' => $this->bulletList($input->homeworkAssigned, 'No homework was set.'),
        ];
    }

    private function summary(AiTaskDescriptor $descriptor): LessonAiSummary
    {
        $summary = $descriptor->correlationId === null
            ? null
            : $this->summaries->find($descriptor->correlationId);

        if ($summary === null) {
            throw new AiException('The lesson summary this run belongs to no longer exists.', AiFailureCode::NotConfigured);
        }

        return $summary;
    }

    private function topic(LessonSummaryInput $input): string
    {
        if ($input->topicLabel === null) {
            return 'not specified';
        }

        return $input->topicDescription === null
            ? $input->topicLabel
            : $input->topicLabel.' — '.$input->topicDescription;
    }

    /** @param list<string> $items */
    private function bulletList(array $items, string $empty): string
    {
        if ($items === []) {
            return $empty;
        }

        return implode("\n", array_map(static fn (string $item): string => '- '.$item, $items));
    }
}
