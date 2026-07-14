<?php

declare(strict_types=1);

namespace App\Reviews\Support;

use App\Models\LessonReview;
use App\Models\User;
use App\Reviews\DTOs\ReviewEditability;
use App\Reviews\Enums\StudentReviewStatus;
use App\Settings\ReviewSettings;
use Carbon\CarbonImmutable;

/**
 * The single "may this student edit this review right now" predicate —
 * used by both EditStudentReviewAction (revalidation under lock) and
 * the portal (display), so they can never disagree. Every denial
 * reason is neutral UI-safe text: no moderation note, report detail,
 * or reporter identity ever leaves this class.
 *
 * The edit window always starts at submitted_at — never publication,
 * moderation, or page-load time. Any review report in ANY status
 * blocks editing: pending/under-review are active locks, and a
 * terminally resolved report (upheld/dismissed/duplicate) stays
 * blocked because no existing policy permits reopening a review whose
 * moderation history is complete.
 */
final class ReviewEditPolicy
{
    private const array EDITABLE_STATUSES = [
        StudentReviewStatus::Submitted,
        StudentReviewStatus::Flagged,
        StudentReviewStatus::Published,
        StudentReviewStatus::Private,
    ];

    public function __construct(
        private readonly ReviewSettings $settings,
    ) {}

    public function evaluate(LessonReview $review, User $student): ReviewEditability
    {
        if ($review->student_id !== $student->id) {
            return ReviewEditability::denied('You may only edit your own review.');
        }

        if (! $this->settings->reviews_enabled || ! $this->settings->review_editing_enabled) {
            return ReviewEditability::denied('Review editing is not available right now.');
        }

        if (! in_array($review->status, self::EDITABLE_STATUSES, strict: true)) {
            return ReviewEditability::denied('This review can no longer be edited.');
        }

        $deadline = CarbonImmutable::instance($review->submitted_at)
            ->addHours($this->settings->review_edit_window_hours);

        if (now()->isAfter($deadline)) {
            return ReviewEditability::denied('The editing period for this review has ended.');
        }

        if ($review->reports()->exists()) {
            return ReviewEditability::denied('This review cannot currently be edited because it has completed or active moderation history.');
        }

        // Historical ownership must still hold structurally — the
        // eligibility and lesson this review was written under must
        // still reference the same student.
        $eligibility = $review->eligibility;
        $lesson = $review->lesson;

        if ($eligibility === null || $lesson === null
            || $eligibility->student_id !== $student->id
            || $lesson->student_id !== $student->id) {
            return ReviewEditability::denied('This review is no longer linked to an eligible lesson.');
        }

        return ReviewEditability::allowed($deadline);
    }
}
