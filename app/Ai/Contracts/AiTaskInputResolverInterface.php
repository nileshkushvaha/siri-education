<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\Exceptions\AiException;

/**
 * Rehydrates prompt variables from their owning domain at execution
 * time, so content never has to travel in a queue payload (see
 * AiTaskDescriptor).
 *
 * Implementations live in the DOMAIN that owns the content — a P3
 * lesson-summary resolver belongs under app/Lessons, not here. That
 * keeps the decision "what is safe to send to a provider" with the team
 * that owns the data.
 */
interface AiTaskInputResolverInterface
{
    /**
     * @return array<string, string> prompt variables
     *
     * @throws AiException when the subject no longer exists or is not eligible
     */
    public function resolve(AiTaskDescriptor $descriptor): array;
}
