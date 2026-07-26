<?php

declare(strict_types=1);

namespace App\Homework\Enums;

/**
 * Media collections supported on HomeworkAssignment. Mirrors
 * InstructorEvidenceCollection's role as a download-route whitelist — a
 * media id belonging to any other collection/model must never resolve
 * through the homework download route, even for an authorized viewer.
 */
enum HomeworkResourceCollection: string
{
    case InstructorResources = 'instructor_resources';
    case SubmissionAttachment = 'submission_attachments';
}
