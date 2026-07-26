<?php

declare(strict_types=1);

namespace App\Homework\Actions;

use App\Homework\Enums\HomeworkResourceCollection;
use App\Homework\Enums\HomeworkStatus;
use App\Homework\Exceptions\HomeworkException;
use App\Models\HomeworkAssignment;
use Illuminate\Http\UploadedFile;

final class SubmitHomeworkAction
{
    /** $attachment is stored atomically with the text submission — see HomeworkServiceInterface::submit(). */
    public function execute(HomeworkAssignment $assignment, string $submissionText, ?UploadedFile $attachment = null): HomeworkAssignment
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

        if ($attachment !== null) {
            $assignment->addMedia($attachment)->toMediaCollection(HomeworkResourceCollection::SubmissionAttachment->value);
        }

        return $assignment->refresh();
    }
}
