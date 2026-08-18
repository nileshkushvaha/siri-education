<?php

declare(strict_types=1);

namespace App\Quality\Intelligence\Services;

use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Jobs\ExecuteAiTaskJob;
use App\Ai\Services\AiBudgetGuard;
use App\Ai\Services\AiFeatureGate;
use App\Models\AiQualityInsight;
use App\Models\User;
use App\Quality\Intelligence\Contracts\QualityInsightRepositoryInterface;
use App\Quality\Intelligence\Contracts\QualityInsightServiceInterface;
use App\Quality\Intelligence\DTOs\QualityInsightData;
use App\Quality\Intelligence\Enums\QualityInsightStatus;
use App\Quality\Intelligence\Exceptions\QualityInsightException;
use App\Quality\Intelligence\Prompts\QualityInsightPrompt;
use App\Quality\Intelligence\Resolvers\QualityInsightInputResolver;
use App\Quality\Intelligence\Resolvers\QualityInsightResultHandler;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of ai_quality_insights.
 *
 * Generation is always asynchronous: request() creates a Pending row
 * and queues the run, so an admin clicking "Generate" never waits on a
 * provider and an outage can never hang an admin page. The row exists
 * before the job does, which is what makes a lost or crashed job
 * visible as a stuck Pending insight rather than as nothing at all.
 *
 * This service reads AI availability through P0's AiFeatureGate and
 * AiBudgetGuard purely to fail FAST and legibly in the UI — the
 * authoritative checks still run inside AiExecutionService, so a race
 * between this check and execution simply produces a Blocked run and a
 * Failed insight, never an unbudgeted call.
 *
 * Every state change is audited: an AI-assisted judgement about a real
 * person's work needs a record of who asked for it and who read it.
 */
final class QualityInsightService implements QualityInsightServiceInterface
{
    public function __construct(
        private readonly QualityInsightRepositoryInterface $insights,
        private readonly AiFeatureGate $gate,
        private readonly AiBudgetGuard $budget,
        private readonly AuditTrailService $audit,
    ) {}

    public function request(User $instructor, ReportingPeriod $period, User $requestedBy): AiQualityInsight
    {
        if (! $instructor->hasRole('instructor')) {
            throw QualityInsightException::notAnInstructor();
        }

        $blocked = $this->gate->blockReason(AiFeature::QualityInsights) ?? $this->budget->blockReason();

        if ($blocked !== null) {
            throw QualityInsightException::aiUnavailable($blocked);
        }

        $existing = $this->insights->pendingFor(
            $instructor,
            $period->preset->value,
            $period->start->toDateString(),
            $period->end->subDay()->toDateString(),
        );

        if ($existing !== null) {
            // Two admins clicking Generate for the same instructor and
            // period would otherwise pay twice for the same answer.
            throw QualityInsightException::alreadyRunning();
        }

        $insight = DB::transaction(function () use ($instructor, $period, $requestedBy): AiQualityInsight {
            $insight = $this->insights->create([
                'instructor_id' => $instructor->id,
                'period_preset' => $period->preset->value,
                'period_start' => $period->start->toDateString(),
                // Stored inclusive — the exclusive boundary is a query
                // detail, and an admin reading "1–31 Aug" must not see
                // "1 Aug – 1 Sep".
                'period_end' => $period->end->subDay()->toDateString(),
                'period_timezone' => $period->timezone,
                'period_label' => $period->label,
                'status' => QualityInsightStatus::Pending,
                'prompt_key' => QualityInsightPrompt::KEY,
                'prompt_version' => QualityInsightPrompt::VERSION,
                'requested_by' => $requestedBy->id,
                'requires_human_review' => true,
            ]);

            $this->audit->logUser(
                $requestedBy,
                'ai_quality_insight',
                'ai_quality_insight_requested',
                'AI quality insight requested',
                $insight,
                ['instructor_id' => $instructor->id, 'period' => $period->label],
            );

            return $insight;
        });

        // Dispatched after the transaction commits: the worker must
        // never look for a row its own transaction has not written yet.
        ExecuteAiTaskJob::dispatch(new AiTaskDescriptor(
            feature: AiFeature::QualityInsights,
            capability: AiCapability::StructuredGeneration,
            promptKey: QualityInsightPrompt::KEY,
            inputResolver: QualityInsightInputResolver::class,
            resultHandler: QualityInsightResultHandler::class,
            correlationId: $insight->getKey(),
            promptVersion: QualityInsightPrompt::VERSION,
            subjectType: $instructor->getMorphClass(),
            subjectId: (string) $instructor->id,
            requestedBy: $requestedBy->id,
        ))->afterCommit();

        return $insight;
    }

    public function storeResult(AiQualityInsight $insight, QualityInsightData $data, ?string $aiRunId): AiQualityInsight
    {
        if ($insight->status->isTerminal()) {
            // A released-and-rerun job may deliver twice; the first
            // answer stands rather than being silently overwritten.
            return $insight;
        }

        return $this->insights->update($insight, [
            'ai_run_id' => $aiRunId,
            'status' => QualityInsightStatus::Ready,
            'failure_code' => null,
            'summary' => $data->summary,
            'strengths' => $data->strengths,
            'concerns' => $data->concerns,
            'recommended_review' => $data->recommendedReview,
            'confidence' => $data->confidence,
            'requires_human_review' => $data->requiresHumanReview,
        ]);
    }

    public function markFailed(AiQualityInsight $insight, ?string $failureCode, ?string $aiRunId = null): AiQualityInsight
    {
        if ($insight->status->isTerminal()) {
            return $insight;
        }

        return $this->insights->update($insight, [
            'status' => QualityInsightStatus::Failed,
            'failure_code' => $failureCode,
            'ai_run_id' => $aiRunId ?: $insight->ai_run_id,
        ]);
    }

    public function markReviewed(AiQualityInsight $insight, User $reviewer, ?string $note = null): AiQualityInsight
    {
        $reviewed = $this->insights->update($insight, [
            'status' => QualityInsightStatus::Reviewed,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $this->audit->logUser(
            $reviewer,
            'ai_quality_insight',
            'ai_quality_insight_reviewed',
            'AI quality insight reviewed',
            $reviewed,
            ['instructor_id' => $reviewed->instructor_id],
        );

        return $reviewed;
    }

    /** Records the sanitized, text-free provenance of what the model was shown. */
    public function recordProvenance(AiQualityInsight $insight, array $provenance): AiQualityInsight
    {
        return $this->insights->update($insight, ['source_snapshot' => $provenance]);
    }
}
