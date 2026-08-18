<?php

declare(strict_types=1);

namespace App\Ai\Evaluation\Contracts;

use App\Ai\Evaluation\Enums\AiFeedbackAction;
use App\Ai\Evaluation\Enums\AiFeedbackReason;
use App\Models\AiFeedbackEvent;

/**
 * The reusable evaluation hook. Any AI feature — existing or future —
 * records a reviewer's verdict through this one method.
 *
 * Takes IDS, never models: the AI module deliberately depends on no
 * business model (AiArchitectureTest enforces it), so callers pass an
 * actor id exactly as AiTaskRequest already passes `requestedBy`.
 */
interface AiFeedbackRecorderInterface
{
    /**
     * Idempotent per (run, reviewer): a reviewer changing their mind
     * updates their verdict rather than adding a second one.
     *
     * Returns null when there is no run to attach the verdict to — a
     * feature whose output came from a blocked or failed run has
     * nothing to evaluate, and a verdict floating free of a run could
     * never be compared across prompt versions.
     */
    public function record(
        ?string $aiRunId,
        AiFeedbackAction $action,
        ?AiFeedbackReason $reason = null,
        ?int $actorId = null,
    ): ?AiFeedbackEvent;
}
