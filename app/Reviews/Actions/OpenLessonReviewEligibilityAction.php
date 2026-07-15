<?php

declare(strict_types=1);

namespace App\Reviews\Actions;

use App\Booking\Enums\BookingPaymentStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Lesson;
use App\Models\LessonReviewEligibility;
use App\Reviews\Contracts\LessonReviewEligibilityRepositoryInterface;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\LessonReviewEligibilityStatus;
use App\Reviews\Enums\ReviewableLessonType;
use App\Reviews\Events\LessonReviewEligibilityOpened;
use App\Services\AuditTrailService;
use App\Settings\ReviewSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Opens (or, for a corrected-back-to-Completed lesson whose eligibility
 * was previously Revoked, restores) public/private review eligibility
 * for a completed lesson — the SRS eligibility matrix in one place.
 *
 * Idempotent by construction: the unique (lesson_id, student_id) index
 * makes a concurrent duplicate insert impossible; this action treats
 * that race as the idempotent case, not a failure. Never called for a
 * non-Completed outcome — the caller (service/reevaluate action) is
 * responsible for that gate.
 */
final class OpenLessonReviewEligibilityAction
{
    private const string LOG_NAME = 'reviews';

    public function __construct(
        private readonly LessonReviewEligibilityRepositoryInterface $eligibilities,
        private readonly AuditTrailService $audit,
        private readonly ReviewSettings $settings,
    ) {}

    /**
     * Self-contained transaction — safe to call directly (initial
     * finalization) or from inside a caller's own transaction (Laravel
     * nests via savepoints). Returns null when policy does not grant
     * eligibility for this lesson — never an error.
     */
    public function execute(Lesson $lesson): ?LessonReviewEligibility
    {
        return DB::transaction(function () use ($lesson): ?LessonReviewEligibility {
            $existing = $this->eligibilities->findForLessonAndStudent($lesson, $lesson->student_id);

            if ($existing !== null) {
                return $existing->status === LessonReviewEligibilityStatus::Revoked
                    ? $this->restore($this->eligibilities->lock($existing), $lesson)
                    : $existing; // already open/used/expired/manual_review — idempotent no-op
            }

            return $this->create($lesson);
        });
    }

    private function create(Lesson $lesson): ?LessonReviewEligibility
    {
        $decision = $this->decide($lesson);

        if ($decision === null) {
            return null;
        }

        [$type, $mode] = $decision;
        $completedAt = $lesson->outcome_finalized_at ?? $lesson->completed_at ?? now();

        try {
            $eligibility = $this->eligibilities->create([
                'lesson_id' => $lesson->id,
                'booking_id' => $lesson->booking_id,
                'student_id' => $lesson->student_id,
                'instructor_id' => $lesson->instructor_id,
                'lesson_type' => $type,
                'eligibility_mode' => $mode,
                'status' => LessonReviewEligibilityStatus::Open,
                'opens_at' => $completedAt->utc(),
                'expires_at' => $completedAt->utc()->addDays($this->settings->review_window_days),
                'outcome_snapshot' => LessonOutcome::Completed->value,
                'source_outcome_version' => $lesson->outcome_version,
                'version' => 1,
                'metadata' => $this->policySnapshot(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent listener/replay won the insert — that IS the
            // idempotent outcome, not a transient failure.
            return $this->eligibilities->findForLessonAndStudent($lesson, $lesson->student_id);
        }

        $this->audit->logSystem(
            self::LOG_NAME,
            'review_eligibility_opened',
            sprintf('Review eligibility opened for lesson %s (%s, %s).', $lesson->id, $type->value, $mode->value),
            $eligibility,
            ['lesson_id' => $lesson->id, 'booking_id' => $lesson->booking_id, 'student_id' => $lesson->student_id],
        );

        LessonReviewEligibilityOpened::dispatch($eligibility);

        return $eligibility;
    }

    /** A previously revoked window, corrected back to Completed — reopen the SAME row, never a new one. */
    private function restore(LessonReviewEligibility $eligibility, Lesson $lesson): ?LessonReviewEligibility
    {
        $decision = $this->decide($lesson);

        if ($decision === null) {
            // Policy no longer grants eligibility — leave the revoked
            // record exactly as it is; restoring would contradict policy.
            return $eligibility;
        }

        [$type, $mode] = $decision;
        $completedAt = $lesson->outcome_finalized_at ?? $lesson->completed_at ?? now();

        $eligibility->history = [...($eligibility->history ?? []), $eligibility->snapshotForHistory()];
        $eligibility->fill([
            'lesson_type' => $type,
            'eligibility_mode' => $mode,
            'status' => LessonReviewEligibilityStatus::Open,
            'opens_at' => $completedAt->utc(),
            'expires_at' => $completedAt->utc()->addDays($this->settings->review_window_days),
            'outcome_snapshot' => LessonOutcome::Completed->value,
            'source_outcome_version' => $lesson->outcome_version,
            'used_at' => null,
            'revoked_at' => null,
            'revoked_reason' => null,
            'version' => $eligibility->version + 1,
            'metadata' => $this->policySnapshot(),
        ])->save();

        $this->audit->logSystem(
            self::LOG_NAME,
            'review_eligibility_restored',
            sprintf('Review eligibility restored for lesson %s after outcome correction.', $lesson->id),
            $eligibility,
            ['lesson_id' => $lesson->id, 'booking_id' => $lesson->booking_id, 'student_id' => $lesson->student_id],
        );

        LessonReviewEligibilityOpened::dispatch($eligibility);

        return $eligibility;
    }

    /**
     * The SRS matrix. Returns null when no eligibility should exist for
     * this lesson under current policy (or the lesson/booking/
     * participants don't structurally qualify).
     *
     * @return array{0: ReviewableLessonType, 1: LessonReviewEligibilityMode}|null
     */
    private function decide(Lesson $lesson): ?array
    {
        if (! $this->settings->reviews_enabled) {
            return null;
        }

        if ($lesson->student_id === null || $lesson->instructor_id === null) {
            return null;
        }

        $booking = $lesson->booking;

        if ($booking === null) {
            return null;
        }

        // Structural guard: the eligibility is for the ACTUAL booking
        // participants — a lesson snapshot that has drifted from its
        // booking is never eligible.
        if ($lesson->student_id !== $booking->student_id || $lesson->instructor_id !== $booking->instructor_id) {
            return null;
        }

        $isPaidType = (bool) ($booking->type?->is_paid ?? false);

        if ($isPaidType) {
            if ($booking->payment_status !== BookingPaymentStatus::Paid) {
                return null;
            }

            return $this->settings->paid_lesson_reviews_enabled
                ? [ReviewableLessonType::Paid, LessonReviewEligibilityMode::PublicReview]
                : null;
        }

        if ($booking->payment_status !== BookingPaymentStatus::NotRequired) {
            return null;
        }

        return match ($this->settings->demo_review_policy) {
            'public' => [ReviewableLessonType::Demo, LessonReviewEligibilityMode::PublicReview],
            'private_only' => [ReviewableLessonType::Demo, LessonReviewEligibilityMode::PrivateFeedback],
            default => null, // 'disabled' or unrecognized
        };
    }

    /** @return array<string, mixed> */
    private function policySnapshot(): array
    {
        return [
            'reviews_enabled' => $this->settings->reviews_enabled,
            'paid_lesson_reviews_enabled' => $this->settings->paid_lesson_reviews_enabled,
            'demo_review_policy' => $this->settings->demo_review_policy,
            'review_window_days' => $this->settings->review_window_days,
        ];
    }
}
