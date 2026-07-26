<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LessonTechnicalIssueReport;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\Gate;

/**
 * Evidence visibility is scoped to the reporter plus whoever can
 * already review lesson attendance (delegates to the existing
 * LessonPolicy::reviewAttendance() ability, reusing ReviewAttendance:Lesson
 * rather than seeding a new permission).
 *
 * Deliberately NOT extended to "the other lesson participant": a
 * report may reflect on the other party's performance (SRS §12.28 —
 * repeated instructor failures affect performance metrics), so
 * evidence stays reporter + admin-reviewer scoped rather than visible
 * to whoever the report is effectively about.
 */
class LessonTechnicalIssueReportPolicy
{
    use HandlesAuthorization;

    public function view(User $user, LessonTechnicalIssueReport $report): bool
    {
        if ($user->id === $report->reported_by) {
            return true;
        }

        return Gate::forUser($user)->allows('reviewAttendance', $report->lesson);
    }
}
