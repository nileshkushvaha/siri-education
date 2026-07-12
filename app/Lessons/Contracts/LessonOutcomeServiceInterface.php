<?php

declare(strict_types=1);

namespace App\Lessons\Contracts;

use App\Lessons\DTOs\LessonOutcomeDetermination;
use App\Lessons\DTOs\OutcomeFinalizationResult;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Exceptions\LessonOutcomeException;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Single entry point for finalizing and correcting lesson outcomes.
 * The outcome is authoritative and separate from booking status; the
 * operational LessonStatus and the parent booking are synchronized
 * through the existing lifecycle engine so its events, notifications,
 * and audit keep firing unchanged.
 */
interface LessonOutcomeServiceInterface
{
    /** Evaluate (never write) the outcome the current evidence supports. */
    public function determine(Lesson $lesson): LessonOutcomeDetermination;

    /**
     * Finalize the lesson outcome — the determined one when $outcome is
     * null, otherwise the requested outcome re-validated against the
     * evidence rules. Idempotent for repeats of the same outcome;
     * changing a terminal outcome throws (use override()).
     *
     * @throws LessonOutcomeException
     * @throws AuthorizationException
     */
    public function finalize(Lesson $lesson, ?LessonOutcome $outcome = null, ?User $actor = null, ?string $notes = null): OutcomeFinalizationResult;

    /**
     * Administrator correction of a (possibly terminal) outcome —
     * requires the OverrideOutcome:Lesson permission and a reason;
     * recorded as an override in the audit trail.
     *
     * @throws LessonOutcomeException
     * @throws AuthorizationException
     */
    public function override(Lesson $lesson, User $admin, LessonOutcome $outcome, string $reason, ?string $notes = null): OutcomeFinalizationResult;
}
