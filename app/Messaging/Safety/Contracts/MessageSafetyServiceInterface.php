<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Contracts;

use App\Messaging\Safety\DTOs\CommunicationRiskData;
use App\Messaging\Safety\DTOs\MessageSafetyWarning;
use App\Models\Message;
use App\Models\MessageSafetyFinding;
use App\Models\User;

/**
 * The communication-safety boundary. Every write to
 * message_safety_findings goes through here.
 */
interface MessageSafetyServiceInterface
{
    /**
     * The pre-send warning for a message a user has not sent yet.
     * Deterministic only — no provider call, no record written, no
     * message required. Returns an empty warning when nothing tripped.
     */
    public function warningFor(string $body): MessageSafetyWarning;

    /**
     * Records what the deterministic rules already found on a sent
     * message. Free, instant and always run; no AI involved.
     */
    public function recordDeterministicFinding(Message $message): ?MessageSafetyFinding;

    /**
     * Queues an AI intent analysis, but only when the triage gate says
     * this message is genuinely ambiguous and AI is available. Returns
     * null when nothing was queued — which is the common case.
     */
    public function requestIntentAnalysis(Message $message): ?MessageSafetyFinding;

    /**
     * Queues a safety classification for a REPORTED message. Never runs
     * on unreported messages.
     */
    public function requestModeration(Message $message): ?MessageSafetyFinding;

    /** Stores a validated intent result. Idempotent. */
    public function storeIntentResult(MessageSafetyFinding $finding, CommunicationRiskData $data, ?string $aiRunId): MessageSafetyFinding;

    /**
     * @param  list<string>  $categories  provider category keys that tripped
     */
    public function storeModerationResult(MessageSafetyFinding $finding, bool $flagged, array $categories, float $highestScore, ?string $aiRunId): MessageSafetyFinding;

    /** The analysis produced nothing worth recording, or could not run. */
    public function discardPending(MessageSafetyFinding $finding, ?string $failureCode = null): void;

    /** An administrator agrees the finding warrants attention. Never restricts anyone. */
    public function confirm(MessageSafetyFinding $finding, User $reviewer, ?string $note = null): MessageSafetyFinding;

    public function dismiss(MessageSafetyFinding $finding, User $reviewer, ?string $note = null): MessageSafetyFinding;
}
