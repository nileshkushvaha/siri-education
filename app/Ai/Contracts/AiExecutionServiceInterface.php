<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\DTOs\AiTaskRequest;
use App\Ai\DTOs\AiTaskResult;

/**
 * THE ONLY ENTRY POINT business code may use to reach AI.
 *
 * Domain features depend on this interface — never on a provider, never
 * on the HTTP client, never on a model name. Everything a run needs to
 * be safe (feature gating, budget, prompt versioning, schema
 * validation, usage recording, safe logging) happens behind it, so no
 * caller can accidentally skip a control.
 */
interface AiExecutionServiceInterface
{
    /** Never throws for a provider/validation failure — see AiTaskResult. */
    public function execute(AiTaskRequest $request): AiTaskResult;
}
