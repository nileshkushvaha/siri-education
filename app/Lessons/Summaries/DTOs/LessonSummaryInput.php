<?php

declare(strict_types=1);

namespace App\Lessons\Summaries\DTOs;

/**
 * EVERYTHING the model may see about a lesson, and nothing else. If a
 * field is not on this object it cannot reach a provider.
 *
 * Structurally absent: the student's and instructor's names, ids and
 * contact details; lesson, booking, plan and homework identifiers;
 * dates and times; prices, earnings and payment state; attendance and
 * no-show outcomes; and the private instructor-to-student feedback
 * record.
 *
 * That last exclusion is deliberate and worth stating. Every lesson may
 * carry an InstructorStudentFeedback row with observations on the
 * student's engagement, attitude and preparedness. It would make a
 * richer prompt — and it is an instructor's private assessment of a
 * child's character, gathered for a different purpose. A summary of
 * what was taught does not need it, so it does not travel.
 *
 * NO RECORDING DATA of any kind is here either: no transcript, no
 * audio, no video, no meeting artifact. Lesson recordings exist in this
 * platform, and using them is a separate phase with its own consent and
 * retention decisions.
 */
final readonly class LessonSummaryInput
{
    /**
     * @param  list<string>  $planObjectives  open milestone titles from the linked learning plan
     * @param  list<string>  $homeworkAssigned  title + brief of homework set for this lesson
     */
    public function __construct(
        public string $subjectLabel,
        public string $academicLevelLabel,
        public ?string $topicLabel,
        public ?string $topicDescription,
        public int $durationMinutes,
        public ?string $instructorNotes,
        public ?string $planFocus,
        public array $planObjectives,
        public array $homeworkAssigned,
    ) {}

    /**
     * A lesson with no instructor note, no topic and no homework leaves
     * the model nothing to summarize but the subject name — and asking
     * it to write a summary from that is exactly how invented detail
     * gets into a student's record.
     */
    public function isTooSparse(): bool
    {
        return $this->instructorNotes === null
            && $this->topicLabel === null
            && $this->homeworkAssigned === [];
    }

    /**
     * The provenance record stored on the summary row: which kinds of
     * context were available, never their content.
     *
     * @return array<string, mixed>
     */
    public function toProvenance(): array
    {
        return [
            'subject' => $this->subjectLabel,
            'academic_level' => $this->academicLevelLabel,
            'topic_present' => $this->topicLabel !== null,
            'duration_minutes' => $this->durationMinutes,
            'instructor_notes_present' => $this->instructorNotes !== null,
            'plan_focus_present' => $this->planFocus !== null,
            'plan_objectives_sent' => count($this->planObjectives),
            'homework_items_sent' => count($this->homeworkAssigned),
        ];
    }
}
