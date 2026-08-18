<?php

declare(strict_types=1);

namespace App\Policies;

use App\Homework\Enums\HomeworkStatus;
use App\Models\HomeworkAiFeedbackDraft;
use App\Models\HomeworkAssignment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Only the assigning instructor, and only ever them.
 *
 * The student is denied `view` deliberately and permanently. A draft is
 * an unreviewed model output about their work — it may say something is
 * wrong that is not, or miss something that matters — and it exists
 * precisely so the tutor can correct it before anything reaches the
 * student. What the student receives is the published feedback on the
 * assignment, which is the tutor's own words.
 *
 * There is no admin surface either. This mirrors
 * HomeworkAssignmentPolicy, which grants nothing to staff: homework is
 * a private matter between one student and their tutor, and adding an
 * admin AI-draft viewer would widen access to student work that the
 * existing homework model deliberately keeps narrow. `super_admin`
 * still bypasses via Gate::before(), exactly as it does for the
 * assignment itself — no wider, no narrower.
 */
class HomeworkAiFeedbackDraftPolicy
{
    use HandlesAuthorization;

    /**
     * Requesting a draft is the moment student work becomes eligible to
     * leave the platform, so it is gated on the same right as publishing
     * feedback (the assigning instructor) plus the work actually being
     * submitted and not yet reviewed.
     */
    public function generate(User $user, HomeworkAssignment $assignment): bool
    {
        return $user->id === $assignment->teacher_id
            && $assignment->status === HomeworkStatus::Submitted;
    }

    public function view(User $user, HomeworkAiFeedbackDraft $draft): bool
    {
        return $user->id === $draft->assignment?->teacher_id;
    }

    /** Using a draft as a starting point, or dismissing it. */
    public function act(User $user, HomeworkAiFeedbackDraft $draft): bool
    {
        return $this->view($user, $draft);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, HomeworkAiFeedbackDraft $draft): bool
    {
        return false;
    }

    public function delete(User $user, HomeworkAiFeedbackDraft $draft): bool
    {
        return false;
    }
}
