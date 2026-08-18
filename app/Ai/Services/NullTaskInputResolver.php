<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Contracts\AiTaskInputResolverInterface;
use App\Ai\DTOs\AiTaskDescriptor;

/**
 * For prompts that take no variables — currently only the diagnostics
 * connectivity check. Also the reference implementation P1-P4 resolvers
 * follow, in their own domain rather than here.
 */
final class NullTaskInputResolver implements AiTaskInputResolverInterface
{
    public function resolve(AiTaskDescriptor $descriptor): array
    {
        return [];
    }
}
