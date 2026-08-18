<?php

declare(strict_types=1);

namespace App\Homework\Copilot\DTOs;

/**
 * EVERYTHING the model may see about a homework submission, and nothing
 * else. If a field is not on this object it cannot reach a provider, so
 * reviewing "what do we send?" means reading one class.
 *
 * Structurally absent: the student's name, id or email; the
 * instructor's identity; assignment/booking/lesson/plan identifiers;
 * due dates, lateness, grades or prior feedback; payment, wallet or
 * earnings data; any authentication data. Attachment BYTES are absent
 * too — only the fact that a file exists travels, because reading it
 * would mean OCR and a far larger data-protection question than this
 * phase answers.
 *
 * A HONEST LIMITATION, stated here because the code cannot fix it:
 * unlike a short review excerpt, a homework submission is the student's
 * own extended writing. Redaction removes names and contact details; it
 * cannot make an essay about a family holiday non-identifying. The
 * protection for that is POSTURE, not technique — instructor-initiated
 * only, one submission at a time, already visible to that instructor,
 * never stored by the AI layer, never scanned in the background. See
 * docs/ai/features/homework-copilot.md.
 */
final readonly class HomeworkCopilotInput
{
    public function __construct(
        public string $subjectLabel,
        public string $academicLevelLabel,
        public string $assignmentTitle,
        public ?string $assignmentBrief,
        public ?string $submissionText,
        /** Original length before truncation, so the model can be told it is reading an extract. */
        public int $originalSubmissionCharacters,
        public bool $wasTruncated,
        public bool $hasAttachment,
    ) {}

    /** Nothing meaningful to draft feedback from — refuse rather than pay for a guess. */
    public function isTooSparse(): bool
    {
        return $this->submissionText === null || mb_strlen(trim($this->submissionText)) < 20;
    }

    /**
     * The provenance record stored on the draft: shape and size only,
     * never the submission itself.
     *
     * @return array<string, mixed>
     */
    public function toProvenance(): array
    {
        return [
            'subject' => $this->subjectLabel,
            'academic_level' => $this->academicLevelLabel,
            'submission_characters' => $this->originalSubmissionCharacters,
            'submission_truncated' => $this->wasTruncated,
            'attachment_present_but_not_sent' => $this->hasAttachment,
            'assignment_brief_sent' => $this->assignmentBrief !== null,
        ];
    }
}
