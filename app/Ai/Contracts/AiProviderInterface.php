<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\DTOs\AiProviderCapabilities;
use App\Ai\DTOs\AiProviderHealth;

/**
 * The base every AI provider adapter implements. Capability interfaces
 * below extend it, so a provider can offer text generation without
 * pretending to offer moderation.
 *
 * THIS INTERFACE IS THE REPLACEABILITY GUARANTEE. Business modules
 * depend on AiExecutionServiceInterface, which depends on these
 * contracts, which OpenAI happens to implement today. Adding Gemini or
 * Claude later means one new folder under Providers/ and one registry
 * line — no domain code changes.
 */
interface AiProviderInterface
{
    /** Stable snake_case key used in settings and persisted on ai_runs.provider. */
    public function name(): string;

    public function capabilities(): AiProviderCapabilities;

    /** Never sends a prompt — a credential/reachability probe only. */
    public function healthCheck(): AiProviderHealth;
}
