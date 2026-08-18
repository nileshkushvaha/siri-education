<?php

declare(strict_types=1);

namespace App\Homework\Copilot\Contracts;

use App\Homework\Copilot\DTOs\HomeworkFeedbackDraftData;
use App\Homework\Copilot\Exceptions\HomeworkCopilotException;
use App\Models\HomeworkAiFeedbackDraft;
use App\Models\HomeworkAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The instructor-facing copilot boundary. Every write to
 * homework_ai_feedback_drafts goes through here.
 */
interface HomeworkFeedbackDraftServiceInterface
{
    /**
     * Creates a Pending draft and queues its run. Never calls a
     * provider synchronously, and never runs unless an instructor
     * explicitly asked.
     *
     * @throws HomeworkCopilotException when AI is unavailable, the work is not submitted, already reviewed, or a run is in flight
     * @throws AuthorizationException when the actor is not the assigning instructor
     */
    public function request(HomeworkAssignment $assignment, User $instructor): HomeworkAiFeedbackDraft;

    /** Stores validated AI output. Idempotent. */
    public function storeResult(HomeworkAiFeedbackDraft $draft, HomeworkFeedbackDraftData $data, ?string $aiRunId): HomeworkAiFeedbackDraft;

    /** Records that a run produced nothing usable, with an instructor-readable reason. Idempotent. */
    public function markFailed(HomeworkAiFeedbackDraft $draft, ?string $failureCode, ?string $aiRunId = null): HomeworkAiFeedbackDraft;

    /**
     * The instructor pulled the draft into their editor. Records
     * provenance only — it publishes nothing and writes nothing to the
     * assignment.
     */
    public function markUsed(HomeworkAiFeedbackDraft $draft, User $instructor): HomeworkAiFeedbackDraft;

    public function discard(HomeworkAiFeedbackDraft $draft, User $instructor): HomeworkAiFeedbackDraft;
}
