<?php

declare(strict_types=1);

namespace App\Lessons\Summaries\Services;

use App\Ai\Support\AiTextRedactor;
use App\Enums\LearningPlanStatus;
use App\Lessons\Summaries\DTOs\LessonSummaryInput;
use App\Models\HomeworkAssignment;
use App\Models\Lesson;

/**
 * Builds the one object the model may see, from structured records the
 * requesting instructor already owns.
 *
 * NO RECORDING DATA IS READ HERE, and none may be added: no transcript,
 * no audio, no video, no meeting artifact. Class recordings exist in
 * this platform (see docs/recordings.md) and using them is a separate
 * phase with its own consent, retention and access decisions. An
 * architecture test asserts this file never touches the Recording
 * domain.
 *
 * Two jobs only: SELECT which fields may travel, and hand the free-text
 * ones to AiTextRedactor with the participants' names. Everything else
 * is a label from an authoritative academic record.
 */
final class LessonSummaryInputBuilder
{
    /**
     * Mirrors the `lessons.completion_notes` column (varchar 1000), so
     * the cap can never silently truncate a note the platform itself
     * accepted — it is a declared floor, not a second, tighter limit an
     * instructor would have no way to discover.
     */
    public const int MAX_NOTE_CHARACTERS = 1000;

    public const int MAX_HOMEWORK_ITEMS = 5;

    private const int MAX_PLAN_OBJECTIVES = 5;

    public function __construct(
        private readonly AiTextRedactor $redactor,
    ) {}

    public function build(Lesson $lesson): LessonSummaryInput
    {
        $lesson->loadMissing([
            'student', 'instructor', 'subject', 'subjectTopic', 'academicLevel',
            'learningPlan.milestones',
        ]);

        $hints = $this->identityHintsFor($lesson);

        return new LessonSummaryInput(
            subjectLabel: $lesson->subject?->name ?? 'not specified',
            academicLevelLabel: $lesson->academicLevel?->name ?? 'not specified',
            topicLabel: $lesson->subjectTopic?->name,
            topicDescription: $this->redactor->redact($lesson->subjectTopic?->description, $hints, 500),
            durationMinutes: $this->durationMinutes($lesson),
            // The instructor's own note about the lesson — the single
            // most useful input, and the only free text of theirs sent.
            instructorNotes: $this->redactor->redact($lesson->completion_notes, $hints, self::MAX_NOTE_CHARACTERS),
            planFocus: $this->redactor->redact($this->activePlanFocus($lesson), $hints, 500),
            planObjectives: $this->planObjectives($lesson, $hints),
            homeworkAssigned: $this->homeworkAssigned($lesson, $hints),
        );
    }

    /**
     * The domain-side half of the name layer: for a lesson the
     * participants are the student and their instructor. The shared
     * redactor takes strings and knows neither.
     *
     * @return list<string>
     */
    private function identityHintsFor(Lesson $lesson): array
    {
        $names = [];

        foreach ([$lesson->student, $lesson->instructor] as $user) {
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
     * Derived from the lesson's own scheduled window. Sent as a
     * DURATION, never as dates: how long the lesson ran is useful
     * context for a summary; when it happened is an identifier.
     */
    private function durationMinutes(Lesson $lesson): int
    {
        if ($lesson->starts_at === null || $lesson->ends_at === null) {
            return 0;
        }

        return (int) round($lesson->starts_at->diffInMinutes($lesson->ends_at));
    }

    /** Only an active plan's focus — an archived plan's is stale context. */
    private function activePlanFocus(Lesson $lesson): ?string
    {
        $plan = $lesson->learningPlan;

        if ($plan === null || $plan->status === LearningPlanStatus::Archived) {
            return null;
        }

        return $plan->current_focus;
    }

    /**
     * Open milestone titles as the lesson's learning objectives. Titles
     * only — a milestone description can carry a tutor's private
     * commentary about the student, and the objective itself is what
     * gives the summary direction.
     *
     * @param  list<string>  $hints
     * @return list<string>
     */
    private function planObjectives(Lesson $lesson, array $hints): array
    {
        $plan = $lesson->learningPlan;

        if ($plan === null || $plan->status === LearningPlanStatus::Archived) {
            return [];
        }

        $objectives = [];

        foreach ($plan->milestones as $milestone) {
            if ($milestone->completed_at !== null) {
                continue;
            }

            $title = $this->redactor->redact($milestone->title, $hints, 200);

            if ($title !== null) {
                $objectives[] = $title;
            }

            if (count($objectives) >= self::MAX_PLAN_OBJECTIVES) {
                break;
            }
        }

        return $objectives;
    }

    /**
     * Homework attached to the same booking as this lesson — what was
     * set, never what the student submitted or was graded. Submissions
     * belong to the Homework Copilot's own boundary and its own
     * instructor-initiated request.
     *
     * @param  list<string>  $hints
     * @return list<string>
     */
    private function homeworkAssigned(Lesson $lesson, array $hints): array
    {
        if ($lesson->booking_id === null) {
            return [];
        }

        return HomeworkAssignment::query()
            ->where('booking_id', $lesson->booking_id)
            ->orderBy('created_at')
            ->limit(self::MAX_HOMEWORK_ITEMS)
            ->get(['title', 'description'])
            ->map(function (HomeworkAssignment $homework) use ($hints): string {
                $title = $this->redactor->redact($homework->title, $hints, 200) ?? 'Untitled';
                $brief = $this->redactor->redact($homework->description, $hints, 400);

                return $brief === null ? $title : $title.' — '.$brief;
            })
            ->all();
    }
}
