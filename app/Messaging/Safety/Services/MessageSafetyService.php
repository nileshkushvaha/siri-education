<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Services;

use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Jobs\ExecuteAiTaskJob;
use App\Ai\Services\AiBudgetGuard;
use App\Ai\Services\AiFeatureGate;
use App\Messaging\Safety\Contracts\MessageSafetyFindingRepositoryInterface;
use App\Messaging\Safety\Contracts\MessageSafetyServiceInterface;
use App\Messaging\Safety\DTOs\CommunicationRiskData;
use App\Messaging\Safety\DTOs\MessageSafetyWarning;
use App\Messaging\Safety\Enums\MessageSafetyCategory;
use App\Messaging\Safety\Enums\MessageSafetyFindingStatus;
use App\Messaging\Safety\Enums\MessageSafetyRiskLevel;
use App\Messaging\Safety\Enums\MessageSafetySource;
use App\Messaging\Safety\Events\MessageSafetyFindingConfirmed;
use App\Messaging\Safety\Prompts\CommunicationRiskPrompt;
use App\Messaging\Safety\Prompts\MessageModerationPrompt;
use App\Messaging\Safety\Resolvers\CommunicationRiskResultHandler;
use App\Messaging\Safety\Resolvers\CommunicationSafetyInputResolver;
use App\Messaging\Safety\Resolvers\MessageModerationResultHandler;
use App\Messaging\Safety\Support\AmbiguousIntentDetector;
use App\Messaging\Support\LeakageDetector;
use App\Models\Message;
use App\Models\MessageSafetyFinding;
use App\Models\User;
use App\Services\AuditTrailService;

/**
 * The only writer of message_safety_findings, and the only place that
 * decides whether a message is worth sending to a provider.
 *
 * THREE LAYERS, IN COST AND INTRUSIVENESS ORDER:
 *
 *   1. Deterministic — LeakageDetector, which already runs on every
 *      send inside MessagingService. This service only RECORDS what it
 *      found; it does not re-implement detection.
 *   2. AI intent — only for messages the deterministic layer did not
 *      explain and that trip AmbiguousIntentDetector. In practice a
 *      small fraction of traffic; everything else never leaves.
 *   3. AI moderation — only for a message a human has REPORTED.
 *
 * NOTHING HERE ENFORCES ANYTHING. No method restricts a user, hides a
 * message, closes a conversation, or changes an account. Confirming a
 * finding records a human's agreement, and that is the furthest this
 * service goes.
 */
final class MessageSafetyService implements MessageSafetyServiceInterface
{
    private const string LOG_NAME = 'message_safety';

    public function __construct(
        private readonly MessageSafetyFindingRepositoryInterface $findings,
        private readonly LeakageDetector $leakage,
        private readonly AmbiguousIntentDetector $triage,
        private readonly AiFeatureGate $gate,
        private readonly AiBudgetGuard $budget,
        private readonly AuditTrailService $audit,
    ) {}

    public function warningFor(string $body): MessageSafetyWarning
    {
        // Deterministic only, and deliberately so: a warning shown while
        // someone is still typing must be instant, free, and identical
        // every time. No provider is involved and nothing is recorded —
        // a user who has not sent a message has done nothing to record.
        return new MessageSafetyWarning($this->leakage->detect($body));
    }

    public function recordDeterministicFinding(Message $message): ?MessageSafetyFinding
    {
        $patterns = $message->flagged_leakage_reasons ?? [];

        if ($patterns === []) {
            return null;
        }

        return $this->findings->upsertForSource($message, MessageSafetySource::Deterministic, [
            'category' => $this->categoryForPatterns($patterns),
            // A pattern match is a fact about the text, not a judgement
            // about how serious it is; medium keeps it visible without
            // implying the platform has concluded anything.
            'risk_level' => MessageSafetyRiskLevel::Medium,
            'detected_patterns' => $patterns,
            'status' => MessageSafetyFindingStatus::Open,
        ]);
    }

    public function requestIntentAnalysis(Message $message): ?MessageSafetyFinding
    {
        $body = (string) $message->body;

        // The gate that keeps automatic analysis from becoming blanket
        // surveillance. Checked BEFORE the feature flag so the ordering
        // is unmistakable: most messages are never eligible at all.
        if (! $this->triage->warrantsAiAnalysis($body)) {
            return null;
        }

        if ($this->gate->blockReason(AiFeature::CommunicationModeration) !== null || $this->budget->blockReason() !== null) {
            return null;
        }

        $existing = $this->findings->findForMessageAndSource($message, MessageSafetySource::AiIntent);

        if ($existing !== null) {
            return $existing;
        }

        $reasons = $this->triage->trippedPhrases($body);

        $finding = $this->findings->upsertForSource($message, MessageSafetySource::AiIntent, [
            'category' => MessageSafetyCategory::OtherPolicyRisk,
            'risk_level' => MessageSafetyRiskLevel::Low,
            'detected_patterns' => $reasons,
            'prompt_key' => CommunicationRiskPrompt::KEY,
            'prompt_version' => CommunicationRiskPrompt::VERSION,
            'status' => MessageSafetyFindingStatus::Open,
        ]);

        // Recorded as a system event, not a user action: no person chose
        // this analysis, and the audit trail should say so.
        $this->audit->logSystem(
            self::LOG_NAME,
            'message_ai_analysis_queued',
            'A message was queued for AI intent analysis by the triage gate.',
            $finding,
            ['triage_reasons' => $reasons],
        );

        $this->dispatch($message, $finding, AiCapability::StructuredGeneration, CommunicationRiskPrompt::KEY, CommunicationRiskPrompt::VERSION, CommunicationRiskResultHandler::class);

        return $finding;
    }

    public function requestModeration(Message $message): ?MessageSafetyFinding
    {
        if ($this->gate->blockReason(AiFeature::CommunicationModeration) !== null || $this->budget->blockReason() !== null) {
            return null;
        }

        $existing = $this->findings->findForMessageAndSource($message, MessageSafetySource::AiModeration);

        if ($existing !== null) {
            return $existing;
        }

        $finding = $this->findings->upsertForSource($message, MessageSafetySource::AiModeration, [
            'category' => MessageSafetyCategory::UnsafeContent,
            'risk_level' => MessageSafetyRiskLevel::Low,
            'prompt_key' => MessageModerationPrompt::KEY,
            'prompt_version' => MessageModerationPrompt::VERSION,
            'status' => MessageSafetyFindingStatus::Open,
        ]);

        $this->dispatch($message, $finding, AiCapability::Moderation, MessageModerationPrompt::KEY, MessageModerationPrompt::VERSION, MessageModerationResultHandler::class);

        return $finding;
    }

    public function storeIntentResult(MessageSafetyFinding $finding, CommunicationRiskData $data, ?string $aiRunId): MessageSafetyFinding
    {
        if ($finding->status->isTerminal()) {
            return $finding;
        }

        if ($data->isClean()) {
            // The common and desirable outcome: analysed, found
            // ordinary, and removed from the queue rather than left
            // sitting there as an implied accusation.
            $this->discardPending($finding);

            return $finding;
        }

        return $this->findings->update($finding, [
            'ai_run_id' => $aiRunId,
            'category' => $data->category,
            'risk_level' => $data->riskLevel,
            'reason' => $data->reason,
            'confidence' => $data->confidence,
        ]);
    }

    public function storeModerationResult(MessageSafetyFinding $finding, bool $flagged, array $categories, float $highestScore, ?string $aiRunId): MessageSafetyFinding
    {
        if ($finding->status->isTerminal()) {
            return $finding;
        }

        if (! $flagged) {
            $this->discardPending($finding);

            return $finding;
        }

        return $this->findings->update($finding, [
            'ai_run_id' => $aiRunId,
            'category' => MessageSafetyCategory::UnsafeContent,
            'risk_level' => $highestScore >= 0.8 ? MessageSafetyRiskLevel::High : MessageSafetyRiskLevel::Medium,
            // The provider's own category keys — never the message text.
            'detected_patterns' => $categories,
            'reason' => 'The safety classifier flagged this message under: '.implode(', ', $categories).'.',
            'confidence' => round($highestScore, 3),
        ]);
    }

    public function discardPending(MessageSafetyFinding $finding, ?string $failureCode = null): void
    {
        if ($finding->status->isTerminal()) {
            return;
        }

        // Deleted rather than kept as "dismissed": nothing was found, so
        // there is no finding. Leaving a row behind would mean an
        // innocent message accumulated a permanent safety record because
        // a phrase gate once looked at it. The model refuses to delete a
        // REVIEWED finding, so this can only ever remove an unreviewed
        // one — guarded above as well as there.
        $finding->delete();
    }

    public function confirm(MessageSafetyFinding $finding, User $reviewer, ?string $note = null): MessageSafetyFinding
    {
        $confirmed = $this->review($finding, $reviewer, MessageSafetyFindingStatus::Confirmed, $note, 'message_safety_finding_confirmed');

        // Announces the HUMAN decision. Whether a pattern of them
        // deserves an account-level compliance flag is the Compliance
        // domain's call, not this service's — and it is reached only
        // through confirmed findings, never through model output.
        MessageSafetyFindingConfirmed::dispatch($confirmed);

        return $confirmed;
    }

    public function dismiss(MessageSafetyFinding $finding, User $reviewer, ?string $note = null): MessageSafetyFinding
    {
        return $this->review($finding, $reviewer, MessageSafetyFindingStatus::Dismissed, $note, 'message_safety_finding_dismissed');
    }

    private function review(MessageSafetyFinding $finding, User $reviewer, MessageSafetyFindingStatus $status, ?string $note, string $event): MessageSafetyFinding
    {
        $reviewed = $this->findings->update($finding, [
            'status' => $status,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $this->audit->logUser(
            $reviewer,
            self::LOG_NAME,
            $event,
            'A message safety finding was reviewed.',
            $reviewed,
            ['source_type' => $reviewed->source_type->value, 'category' => $reviewed->category?->value],
        );

        return $reviewed;
    }

    /** @param class-string $handler */
    private function dispatch(Message $message, MessageSafetyFinding $finding, AiCapability $capability, string $promptKey, string $promptVersion, string $handler): void
    {
        ExecuteAiTaskJob::dispatch(new AiTaskDescriptor(
            feature: AiFeature::CommunicationModeration,
            capability: $capability,
            promptKey: $promptKey,
            inputResolver: CommunicationSafetyInputResolver::class,
            resultHandler: $handler,
            correlationId: $finding->getKey(),
            promptVersion: $promptVersion,
            subjectType: $message->getMorphClass(),
            subjectId: (string) $message->getKey(),
            // No requestedBy: nobody asked for this analysis. Leaving it
            // null keeps ai_runs honest about which runs a person chose.
            requestedBy: null,
        ))->afterCommit();
    }

    /** @param list<string> $patterns */
    private function categoryForPatterns(array $patterns): MessageSafetyCategory
    {
        // An off-platform keyword covers both payment apps and messaging
        // apps; contact sharing is the safer, less accusatory reading
        // when only that fired.
        return in_array('off_platform_keyword', $patterns, true) && count($patterns) === 1
            ? MessageSafetyCategory::PaymentBypass
            : MessageSafetyCategory::ContactSharing;
    }
}
