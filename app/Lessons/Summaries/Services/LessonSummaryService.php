<?php

declare(strict_types=1);

namespace App\Lessons\Summaries\Services;

use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Jobs\ExecuteAiTaskJob;
use App\Ai\Services\AiBudgetGuard;
use App\Ai\Services\AiFeatureGate;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Summaries\Contracts\LessonSummaryRepositoryInterface;
use App\Lessons\Summaries\Contracts\LessonSummaryServiceInterface;
use App\Lessons\Summaries\DTOs\LessonSummaryDraftData;
use App\Lessons\Summaries\Enums\LessonSummaryStatus;
use App\Lessons\Summaries\Exceptions\LessonSummaryException;
use App\Lessons\Summaries\Prompts\LessonSummaryPrompt;
use App\Lessons\Summaries\Resolvers\LessonSummaryInputResolver;
use App\Lessons\Summaries\Resolvers\LessonSummaryResultHandler;
use App\Models\Lesson;
use App\Models\LessonAiSummary;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of lesson_ai_summaries.
 *
 * GENERATION IS EXPLICITLY INSTRUCTOR-INITIATED. There is no listener
 * on LessonCompleted, no scheduled sweep and no bulk path in this
 * phase — a lesson's context is only sent to a provider because its own
 * instructor asked, one lesson at a time. Wiring this to the existing
 * LessonCompleted event would be a two-line change and is deliberately
 * not made: automatic generation after every lesson turns an assistant
 * into a background data flow nobody chose.
 *
 * It also never touches the lesson. Status, outcome, completion notes,
 * learning-plan progress and milestones are all owned elsewhere and are
 * not written here under any circumstance.
 */
final class LessonSummaryService implements LessonSummaryServiceInterface
{
    public function __construct(
        private readonly LessonSummaryRepositoryInterface $summaries,
        private readonly AiFeatureGate $gate,
        private readonly AiBudgetGuard $budget,
        private readonly AuditTrailService $audit,
    ) {}

    public function request(Lesson $lesson, User $instructor): LessonAiSummary
    {
        if ($instructor->id !== $lesson->instructor_id) {
            throw new AuthorizationException('Only the lesson\'s instructor can request a summary.');
        }

        // The finalized OUTCOME, not merely the status: a lesson can
        // sit Completed while its outcome is still under dispute or
        // technical-issue hold, and summarising a lesson whose delivery
        // is contested would be documenting something unresolved.
        if ($lesson->outcome !== LessonOutcome::Completed) {
            throw LessonSummaryException::notCompleted();
        }

        $existing = $this->summaries->forLesson($lesson);

        if ($existing?->status === LessonSummaryStatus::Approved) {
            throw LessonSummaryException::alreadyApproved();
        }

        if ($existing?->status === LessonSummaryStatus::Pending) {
            throw LessonSummaryException::alreadyGenerating();
        }

        $blocked = $this->gate->blockReason(AiFeature::LessonSummary) ?? $this->budget->blockReason();

        if ($blocked !== null) {
            throw LessonSummaryException::aiUnavailable($blocked);
        }

        $summary = DB::transaction(function () use ($lesson, $instructor, $existing): LessonAiSummary {
            $attributes = [
                'lesson_id' => $lesson->getKey(),
                'requested_by' => $instructor->id,
                'status' => LessonSummaryStatus::Pending,
                'failure_code' => null,
                'prompt_key' => LessonSummaryPrompt::KEY,
                'prompt_version' => LessonSummaryPrompt::VERSION,
                'requires_instructor_review' => true,
            ];

            // One row per lesson: regenerating after a discarded or
            // failed attempt replaces the draft in place rather than
            // leaving competing accounts of the same lesson behind.
            $summary = $existing === null
                ? $this->summaries->create($attributes)
                : $this->summaries->update($existing, [
                    ...$attributes,
                    'ai_run_id' => null,
                    'lesson_summary' => null,
                    'topics_covered' => null,
                    'strengths_observed' => null,
                    'practice_recommendations' => null,
                    'next_focus' => null,
                    'confidence' => null,
                    'discarded_at' => null,
                ]);

            $this->audit->logUser(
                $instructor,
                'lesson_ai_summary',
                'lesson_ai_summary_requested',
                'AI lesson summary requested',
                $summary,
                ['lesson_id' => $lesson->getKey()],
            );

            return $summary;
        });

        ExecuteAiTaskJob::dispatch(new AiTaskDescriptor(
            feature: AiFeature::LessonSummary,
            capability: AiCapability::StructuredGeneration,
            promptKey: LessonSummaryPrompt::KEY,
            inputResolver: LessonSummaryInputResolver::class,
            resultHandler: LessonSummaryResultHandler::class,
            correlationId: $summary->getKey(),
            promptVersion: LessonSummaryPrompt::VERSION,
            subjectType: $lesson->getMorphClass(),
            subjectId: (string) $lesson->getKey(),
            requestedBy: $instructor->id,
        ))->afterCommit();

        return $summary;
    }

    public function storeResult(LessonAiSummary $summary, LessonSummaryDraftData $data, ?string $aiRunId): LessonAiSummary
    {
        if ($summary->status->isTerminal()) {
            return $summary;
        }

        return $this->summaries->update($summary, [
            'ai_run_id' => $aiRunId,
            'status' => LessonSummaryStatus::Ready,
            'failure_code' => null,
            'lesson_summary' => $data->lessonSummary,
            'topics_covered' => $data->topicsCovered,
            'strengths_observed' => $data->strengthsObserved,
            'practice_recommendations' => $data->practiceRecommendations,
            'next_focus' => $data->nextFocus,
            'confidence' => $data->confidence,
            'requires_instructor_review' => true,
        ]);
    }

    public function markFailed(LessonAiSummary $summary, ?string $failureCode, ?string $aiRunId = null): LessonAiSummary
    {
        if ($summary->status->isTerminal()) {
            return $summary;
        }

        return $this->summaries->update($summary, [
            'status' => LessonSummaryStatus::Failed,
            'failure_code' => $failureCode,
            'ai_run_id' => $aiRunId ?: $summary->ai_run_id,
        ]);
    }

    public function approve(LessonAiSummary $summary, User $instructor, string $approvedText): LessonAiSummary
    {
        $this->assertOwner($summary, $instructor);

        if (! $summary->status->isActionable()) {
            return $summary;
        }

        $approved = $this->summaries->update($summary, [
            'status' => LessonSummaryStatus::Approved,
            // Stored in its OWN column: the draft is retained beside it,
            // so "a model suggested this" and "a tutor approved this"
            // stay distinguishable for the life of the record.
            'approved_summary' => trim($approvedText),
            'approved_by' => $instructor->id,
            'approved_at' => now(),
        ]);

        $this->audit->logUser(
            $instructor,
            'lesson_ai_summary',
            'lesson_ai_summary_approved',
            'AI lesson summary approved',
            $approved,
            ['lesson_id' => $approved->lesson_id],
        );

        return $approved;
    }

    public function discard(LessonAiSummary $summary, User $instructor): LessonAiSummary
    {
        $this->assertOwner($summary, $instructor);

        if ($summary->status === LessonSummaryStatus::Approved) {
            return $summary;
        }

        return $this->summaries->update($summary, [
            'status' => LessonSummaryStatus::Discarded,
            'discarded_at' => now(),
        ]);
    }

    /** Records the content-free provenance of what the model was shown. */
    public function recordProvenance(LessonAiSummary $summary, array $provenance): LessonAiSummary
    {
        return $this->summaries->update($summary, ['source_snapshot' => $provenance]);
    }

    private function assertOwner(LessonAiSummary $summary, User $instructor): void
    {
        if ($instructor->id !== $summary->lesson?->instructor_id) {
            throw new AuthorizationException('Only the lesson\'s instructor can act on this summary.');
        }
    }
}
