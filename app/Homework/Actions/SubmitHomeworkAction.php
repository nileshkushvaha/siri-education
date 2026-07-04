<?php

declare(strict_types=1);

namespace App\Homework\Actions;

use App\Homework\Enums\HomeworkStatus;
use App\Homework\Exceptions\HomeworkException;
use App\Models\HomeworkAssignment;

final class SubmitHomeworkAction
{
    public function execute(HomeworkAssignment $assignment, string $submissionText): HomeworkAssignment
    {
        if ($assignment->status !== HomeworkStatus::Pending) {
            throw new HomeworkException('This assignment has already been submitted.');
        }

        $assignment->fill([
            'status' => HomeworkStatus::Submitted,
            'submission_text' => $submissionText,
            'submitted_at' => now(),
        ]);
        $assignment->save();

        return $assignment->refresh();
    }
}
