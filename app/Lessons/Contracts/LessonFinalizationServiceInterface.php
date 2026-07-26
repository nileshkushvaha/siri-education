<?php

declare(strict_types=1);

namespace App\Lessons\Contracts;

/**
 * Automated post-lesson processing: seals due attendance
 * records, determines outcomes from the evidence, and finalizes them —
 * everything flows through the existing attendance/outcome services and
 * FinalizeLessonOutcomeAction; this layer only decides *when*.
 */
interface LessonFinalizationServiceInterface
{
    /**
     * Process every due open lesson (idempotent, batch-chunked,
     * per-lesson failure isolated). Returns the number of lessons
     * finalized this run. No-op while
     * lessons.automated_finalization_enabled is off.
     */
    public function processDue(): int;
}
