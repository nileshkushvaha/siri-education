<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Prompts;

use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiModelRole;
use App\Ai\Prompts\PromptDefinition;

/**
 * `message_moderation:v1` — the platform's first use of P0's
 * ModerationProviderInterface, registered by MessagingServiceProvider.
 *
 * This prompt carries no instructions, and that is correct rather than
 * lazy: the moderation capability sends content to a purpose-built
 * classifier, not a chat model. There is no system prompt to obey and
 * no output to shape — the provider returns its own fixed categories.
 * The definition exists so the call still travels the normal path:
 * versioned, gated, budgeted, and recorded on ai_runs like every other
 * AI request. `{{ message }}` is the only variable, and the capability
 * uses the rendered USER template as the content to classify.
 *
 * RUNS ONLY ON A REPORTED MESSAGE. Abuse classification is not applied
 * to every message anyone sends — that would be exactly the blanket
 * surveillance this phase excludes. A human reporting a message is the
 * initiating act, and the classification exists to help an admin triage
 * the report they now have to read.
 */
final class MessageModerationPrompt
{
    public const string KEY = 'message_moderation';

    public const string VERSION = 'v1';

    public static function definition(): PromptDefinition
    {
        return new PromptDefinition(
            key: self::KEY,
            version: self::VERSION,
            feature: AiFeature::CommunicationModeration,
            capability: AiCapability::Moderation,
            // The classifier ignores both; the system template is kept
            // minimal and factual rather than instructional.
            systemTemplate: 'Content safety classification for a reported message on a tutoring platform.',
            userTemplate: '{{ message }}',
            schemaKey: null,
            modelRole: AiModelRole::Moderation,
            maxOutputTokens: 16,
            temperature: 0.0,
        );
    }
}
