<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\DTOs\AiModerationRequest;
use App\Ai\DTOs\AiModerationResult;
use App\Ai\Exceptions\AiProviderException;

/**
 * Content classification for P4 (message moderation, contact-sharing
 * detection). The abstraction is created now so the messaging domain
 * never grows a direct provider dependency later; no moderation rule,
 * threshold or enforcement exists in P0.
 */
interface ModerationProviderInterface extends AiProviderInterface
{
    /** @throws AiProviderException */
    public function moderate(AiModerationRequest $request): AiModerationResult;
}
