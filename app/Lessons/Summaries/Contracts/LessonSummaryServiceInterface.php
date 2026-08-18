<?php

declare(strict_types=1);

namespace App\Lessons\Summaries\Contracts;

use App\Lessons\Summaries\DTOs\LessonSummaryDraftData;
use App\Lessons\Summaries\Exceptions\LessonSummaryException;
use App\Models\Lesson;
use App\Models\LessonAiSummary;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The instructor-facing lesson-summary boundary. Every write to
 * lesson_ai_summaries goes through here.
 */
interface LessonSummaryServiceInterface
{
    /**
     * Creates a Pending summary and queues its run. Never calls a
     * provider synchronously, and never runs unless the lesson's own
     * instructor explicitly asked.
     *
     * @throws LessonSummaryException when AI is unavailable, the lesson is not completed, already approved, or a run is in flight
     * @throws AuthorizationException when the actor is not the lesson's instructor
     */
    public function request(Lesson $lesson, User $instructor): LessonAiSummary;

    /** Stores validated AI output. Idempotent. */
    public function storeResult(LessonAiSummary $summary, LessonSummaryDraftData $data, ?string $aiRunId): LessonAiSummary;

    /** Records that a run produced nothing usable. Idempotent. */
    public function markFailed(LessonAiSummary $summary, ?string $failureCode, ?string $aiRunId = null): LessonAiSummary;

    /**
     * The instructor approves their own edited text as the lesson's
     * summary of record. $approvedText is what THEY submitted — never
     * defaulted from the draft by this method.
     */
    public function approve(LessonAiSummary $summary, User $instructor, string $approvedText): LessonAiSummary;

    public function discard(LessonAiSummary $summary, User $instructor): LessonAiSummary;
}
