<?php

declare(strict_types=1);

namespace App\Homework\Actions;

use App\Homework\Enums\HomeworkStatus;
use App\Homework\Exceptions\HomeworkException;
use App\Models\HomeworkAssignment;

final class ReviewHomeworkAction
{
    public function execute(HomeworkAssignment $assignment, string $feedback, ?string $grade): HomeworkAssignment
    {
        if ($assignment->status !== HomeworkStatus::Submitted) {
            throw new HomeworkException('Only submitted homework can be reviewed.');
        }

        $assignment->fill([
            'status' => HomeworkStatus::Graded,
            'feedback' => $feedback,
            'grade' => $grade,
        ]);
        $assignment->save();

        return $assignment->refresh();
    }
}
