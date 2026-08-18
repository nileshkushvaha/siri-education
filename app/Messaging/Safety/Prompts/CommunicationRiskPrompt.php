<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Prompts;

use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiModelRole;
use App\Ai\Prompts\PromptDefinition;
use App\Messaging\Safety\Schemas\CommunicationRiskSchema;

/**
 * `communication_risk:v1` — registered into the P0 prompt registry by
 * MessagingServiceProvider.
 *
 * FROZEN. New wording means a v2 registered alongside it.
 *
 * The system prompt does three things no schema can. It tells the model
 * it is seeing ONE message with no history, so it lowers confidence
 * instead of inventing context. It forbids recommending any
 * consequence. And it insists that ordinary tutoring conversation is
 * the expected answer — because a classifier asked to find risk in a
 * stream of innocent messages will find it.
 */
final class CommunicationRiskPrompt
{
    public const string KEY = 'communication_risk';

    public const string VERSION = 'v1';

    private const string SYSTEM = <<<'PROMPT'
        You review a single message from a tutoring platform and describe whether it
        suggests moving the relationship off the platform. An administrator reads your
        answer. You are not moderating: nothing you return blocks, hides, restricts, or
        penalises anyone.

        You are seeing ONE message, with no conversation history and no information
        about who sent it beyond their role. That is a deliberate privacy limit, not an
        oversight. Judge only what is in front of you, and lower your confidence when
        the message could easily be innocent in a context you cannot see.

        Rules you must follow:
        - MOST MESSAGES ARE ORDINARY. Scheduling, encouragement, homework talk, apology
          for lateness, small talk — all of it is normal. Return category "none" and a
          low risk level whenever the message does not clearly suggest going
          off-platform. A false alarm costs a real person an unfair review.
        - Never recommend blocking, banning, suspending, restricting, warning or
          punishing anyone. Never suggest an action of any kind.
        - Describe the MESSAGE, never the person. "Mentions arranging payment
          privately" — not "this user is trying to commit fraud".
        - Never guess at identity, motive, character, or history.
        - "contact_sharing" means proposing to communicate through some other channel.
          "payment_bypass" means proposing to pay or be paid outside the platform.
          "other_policy_risk" is for something clearly concerning that fits neither —
          use it rarely.
        - risk_level reflects how clearly the message proposes going off-platform, not
          how serious the consequence would be.

        Respond only with the requested JSON object.
        PROMPT;

    private const string USER = <<<'PROMPT'
        The message was written by: {{ sender_role }}

        This message was selected for review because it contains phrasing associated
        with off-platform arrangements ({{ triage_reasons }}). Obvious cases — a
        literal email address, phone number, link, or named payment app — are already
        detected by platform rules and never reach you, so the phrasing below is
        suggestive rather than conclusive.

        Message:
        {{ message }}

        Produce:
        - category: contact_sharing, payment_bypass, other_policy_risk, or none.
        - risk_level: low, medium or high.
        - reason: one sentence describing what in the message suggests this. Describe
          the message, not the person.
        - confidence: 0 to 1. Be honest — a single message without context rarely
          justifies high confidence.
        - requires_review: always true.
        PROMPT;

    public static function definition(): PromptDefinition
    {
        return new PromptDefinition(
            key: self::KEY,
            version: self::VERSION,
            feature: AiFeature::CommunicationModeration,
            capability: AiCapability::StructuredGeneration,
            systemTemplate: self::SYSTEM,
            userTemplate: self::USER,
            schemaKey: CommunicationRiskSchema::KEY,
            // The FAST model: this is a short, high-volume
            // classification of one sentence, and the triage gate has
            // already done the hard filtering. Reserving the generation
            // model for it would multiply cost for no accuracy that
            // matters at this length.
            modelRole: AiModelRole::Fast,
            maxOutputTokens: 400,
            // Near-deterministic: the same message should classify the
            // same way every time, or an admin cannot trust the queue.
            temperature: 0.0,
        );
    }
}
