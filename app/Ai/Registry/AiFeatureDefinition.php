<?php

declare(strict_types=1);

namespace App\Ai\Registry;

use App\Ai\Contracts\AiTaskInputResolverInterface;
use App\Ai\Contracts\AiTaskResultHandlerInterface;
use App\Ai\Enums\AiFeature;

/**
 * One AI feature's declared, approved shape: which prompts it may use,
 * which resolver may read data on its behalf, which handler may receive
 * its output, and whether a human actor is required.
 *
 * WHY THIS EXISTS. Before it, ExecuteAiTaskJob resolved whatever
 * class-string its payload named straight out of the container and
 * called `resolve()` on it. Nothing checked that the class was a
 * resolver at all, let alone the resolver approved for that feature —
 * so the boundary that decides which platform data may reach a provider
 * was, in effect, whatever a caller wrote into a descriptor. This turns
 * that into an allowlist: a feature can only ever read through the
 * resolver it declared.
 *
 * `ownerDomain` and `purpose` are documentation, deliberately required:
 * a future developer adding a feature has to state who owns it and what
 * it is for before it can run at all.
 */
final readonly class AiFeatureDefinition
{
    /**
     * @param  class-string<AiTaskInputResolverInterface>  $inputResolver  the ONLY class that may read platform data for this feature
     * @param  list<class-string<AiTaskResultHandlerInterface>>  $resultHandlers  every class permitted to receive this feature's output — a list because a feature may legitimately have more than one output path (Communication Safety has two: intent analysis and moderation-on-report)
     * @param  list<string>  $allowedPromptKeys  prompts this feature may run — a feature can never borrow another's prompt
     * @param  bool  $requiresAuthenticatedActor  false only for genuinely system-initiated analysis (see AiFeature::CommunicationModeration)
     */
    public function __construct(
        public AiFeature $feature,
        public string $ownerDomain,
        public string $purpose,
        public string $inputResolver,
        public array $resultHandlers,
        public array $allowedPromptKeys,
        public bool $requiresAuthenticatedActor = true,
    ) {}

    public function allowsResolver(string $class): bool
    {
        return $class === $this->inputResolver;
    }

    public function allowsHandler(?string $class): bool
    {
        // A feature that declared no handler may not acquire one from a
        // payload — that would be a new place its output could land.
        return $class === null || in_array($class, $this->resultHandlers, true);
    }

    public function allowsPrompt(string $promptKey): bool
    {
        return in_array($promptKey, $this->allowedPromptKeys, true);
    }
}
