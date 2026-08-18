<?php

declare(strict_types=1);

namespace App\Ai\Prompts;

use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiModelRole;
use App\Ai\Schemas\ConnectivityCheckSchema;

/**
 * Every prompt the platform ships. AiServiceProvider registers these.
 *
 * P0 REGISTERS EXACTLY ONE PROMPT, and it is infrastructure: the
 * connectivity check behind the admin "Test connection" action. The
 * product prompts named in the roadmap —
 *
 *     quality_insight:v1      (P1)
 *     homework_feedback:v1    (P2)
 *     lesson_summary:v1       (P3)
 *     message_moderation:v1   (P4)
 *
 * — are NOT registered here, because writing them is writing the
 * feature: a prompt encodes what the platform asks about a student's
 * work and what it will accept back, which needs the owning phase's
 * product and safety review, not a placeholder. Their absence is
 * enforced, not incidental: AiFeatureGate refuses a disabled feature
 * before the registry is even consulted, and a missing key fails
 * closed as AiFailureCode::PromptMissing.
 *
 * To add one in P1-P4: register a PromptDefinition here (plus its
 * schema in AiSchemaCatalog) and turn on the matching AiSettings flag.
 * Nothing in this module changes.
 */
final class AiPromptCatalog
{
    /** @return list<PromptDefinition> */
    public static function definitions(): array
    {
        return [
            new PromptDefinition(
                key: 'platform_connectivity_check',
                version: 'v1',
                feature: AiFeature::PlatformDiagnostics,
                capability: AiCapability::StructuredGeneration,
                // Carries no variables at all, so the one prompt P0 can
                // actually send is provably incapable of transmitting
                // student data — an admin verifying credentials never
                // ships content to the provider to do it.
                systemTemplate: 'You are a connectivity probe for an education platform. Reply only with the requested JSON object.',
                userTemplate: 'Return {"ok": true} to confirm this request completed.',
                schemaKey: ConnectivityCheckSchema::KEY,
                modelRole: AiModelRole::Fast,
                maxOutputTokens: 64,
                temperature: 0.0,
            ),
        ];
    }
}
