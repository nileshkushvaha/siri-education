<?php

declare(strict_types=1);

namespace App\Policies;

use App\Lessons\Enums\LessonOutcome;
use App\Models\Lesson;
use App\Models\LessonAiSummary;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Generating and approving belong to the lesson's own instructor.
 * Viewing follows the platform's existing lesson-visibility rule for
 * STAFF, and deliberately breaks from it for STUDENTS.
 *
 * `LessonPolicy::view()` grants any participant — which includes the
 * student. That is right for the lesson itself and wrong here: a
 * summary is a professional record the tutor writes about their own
 * teaching, and its draft is unreviewed model output. In this release
 * no student surface exposes one at all; whether an approved summary
 * ever reaches a student's timeline is a separate product decision,
 * not something this policy should pre-authorise.
 *
 * Staff holding `View:Lesson` may read a summary, matching how they can
 * already read the lesson it describes — no wider, no narrower. They
 * may never generate or approve: writing up a lesson is the teaching
 * professional's responsibility, and an admin approving on their behalf
 * would put a name to a record they did not make.
 *
 * `super_admin` bypasses via Gate::before() — never replicated here.
 */
class LessonAiSummaryPolicy
{
    use HandlesAuthorization;

    /**
     * The lesson's instructor, and only once the lesson's OUTCOME is
     * Completed — a disputed or held lesson is not settled enough to
     * document.
     */
    public function generate(User $user, Lesson $lesson): bool
    {
        return $user->id === $lesson->instructor_id
            && $lesson->outcome === LessonOutcome::Completed;
    }

    public function view(User $user, LessonAiSummary $summary): bool
    {
        if ($user->id === $summary->lesson?->instructor_id) {
            return true;
        }

        // Students are never granted here, even though they are lesson
        // participants — see the class docblock.
        if ($user->id === $summary->lesson?->student_id) {
            return false;
        }

        return $this->hasPermission($user, 'View:Lesson');
    }

    /** Approving or discarding a draft — the instructor's alone. */
    public function act(User $user, LessonAiSummary $summary): bool
    {
        return $user->id === $summary->lesson?->instructor_id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, LessonAiSummary $summary): bool
    {
        return false;
    }

    public function delete(User $user, LessonAiSummary $summary): bool
    {
        return false;
    }

    private function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
