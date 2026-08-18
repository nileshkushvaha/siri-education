<?php

declare(strict_types=1);

namespace App\Homework\Copilot\Services;

use App\Ai\Support\AiTextRedactor;
use App\Homework\Copilot\DTOs\HomeworkCopilotInput;
use App\Homework\Enums\HomeworkResourceCollection;
use App\Models\HomeworkAssignment;

/**
 * Builds the one object the model is allowed to see, from a submission
 * the requesting instructor can already read.
 *
 * Two jobs, and only these two: SELECT the fields that may travel, and
 * hand each of them to AiTextRedactor with the participants' names. The
 * academic context is taken from the linked learning plan's real
 * Subject and AcademicLevel where one exists — those are the platform's
 * authoritative academic records — and falls back to the assignment's
 * own free-text subject otherwise. No academic context is invented.
 *
 * The instructor-authored fields (title, brief) are redacted too. They
 * are not student content, but instructors routinely write "as we
 * discussed, Maya, focus on..." — so the same floor applies rather than
 * assuming authorship makes text safe.
 */
final class HomeworkCopilotInputBuilder
{
    /**
     * Long enough for a full school essay, capped so a pasted document
     * cannot turn one draft request into a bulk content transfer. A
     * truncated submission is declared to the model, never silently cut.
     */
    public const int MAX_SUBMISSION_CHARACTERS = 6000;

    public const int MAX_BRIEF_CHARACTERS = 1500;

    public function __construct(
        private readonly AiTextRedactor $redactor,
    ) {}

    public function build(HomeworkAssignment $assignment): HomeworkCopilotInput
    {
        $assignment->loadMissing(['student', 'teacher', 'learningPlan.subject', 'learningPlan.academicLevel']);

        $hints = $this->identityHintsFor($assignment);

        $submission = (string) ($assignment->submission_text ?? '');
        $originalLength = mb_strlen($submission);

        return new HomeworkCopilotInput(
            subjectLabel: $this->subjectLabel($assignment),
            academicLevelLabel: $assignment->learningPlan?->academicLevel?->name ?? 'not specified',
            assignmentTitle: (string) ($this->redactor->redact($assignment->title, $hints, 200) ?? 'Untitled assignment'),
            assignmentBrief: $this->redactor->redact($assignment->description, $hints, self::MAX_BRIEF_CHARACTERS),
            submissionText: $this->redactor->redact($submission, $hints, self::MAX_SUBMISSION_CHARACTERS),
            originalSubmissionCharacters: $originalLength,
            wasTruncated: $originalLength > self::MAX_SUBMISSION_CHARACTERS,
            // The FACT of an attachment travels so the model can tell the
            // tutor to review it directly. The bytes never do: reading
            // them would mean OCR or file upload to a provider, which is
            // a far larger data-protection decision than this phase makes.
            hasAttachment: $assignment->getMedia(HomeworkResourceCollection::SubmissionAttachment->value)->isNotEmpty(),
        );
    }

    /**
     * The domain-side half of the name layer: for a homework
     * submission the participants are the student and their tutor, and
     * these are the fields that carry their names. The shared redactor
     * takes strings and knows neither.
     *
     * @return list<string>
     */
    private function identityHintsFor(HomeworkAssignment $assignment): array
    {
        $names = [];

        foreach ([$assignment->student, $assignment->teacher] as $user) {
            if ($user === null) {
                continue;
            }

            $names[] = (string) $user->name;
            $names[] = (string) $user->first_name;
            $names[] = (string) $user->last_name;
            $names[] = (string) $user->full_name;
        }

        return $this->redactor->normalizeHints($names);
    }

    /**
     * The learning plan's real Subject is authoritative when the
     * assignment is plan-linked; `homework_assignments.subject` is a
     * legacy free-text column and is only a fallback.
     */
    private function subjectLabel(HomeworkAssignment $assignment): string
    {
        $planSubject = $assignment->learningPlan?->subject?->name;

        if (filled($planSubject)) {
            return (string) $planSubject;
        }

        return filled($assignment->subject) ? (string) $assignment->subject : 'not specified';
    }
}
