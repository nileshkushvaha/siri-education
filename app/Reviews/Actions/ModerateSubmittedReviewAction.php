<?php

declare(strict_types=1);

namespace App\Reviews\Actions;

use App\Models\LessonReview;
use App\Reviews\Contracts\LessonReviewRepositoryInterface;
use App\Reviews\Enums\StudentReviewStatus;
use App\Reviews\Events\StudentReviewPublished;
use App\Services\AuditTrailService;
use App\Settings\ReviewSettings;
use Illuminate\Support\Facades\DB;

/**
 * Automatic moderation, run once per newly submitted review (triggered
 * by StudentReviewSubmitted). Never reprocesses or resanitizes the
 * original text — it only reads what Phase 17I already computed and
 * stored (`review_mode`, `sanitization_metadata.had_unsafe_content`)
 * against the CURRENT moderation settings, and snapshots those
 * settings onto the review so a later settings change never
 * retroactively explains a past decision differently.
 *
 * Idempotent: only ever acts on a review still in `Submitted` — a
 * duplicate/replayed event finds the review already moved on (or
 * still deliberately Submitted under pre_moderation) and no-ops.
 * Private and Flagged reviews are never touched here: private feedback
 * must never become public automatically, and a flagged review always
 * waits for a human regardless of model.
 */
final class ModerateSubmittedReviewAction
{
    private const string LOG_NAME = 'reviews';

    public function __construct(
        private readonly LessonReviewRepositoryInterface $reviews,
        private readonly TransitionReviewStatusAction $transition,
        private readonly AuditTrailService $audit,
        private readonly ReviewSettings $settings,
    ) {}

    public function execute(LessonReview $review): LessonReview
    {
        return DB::transaction(function () use ($review): LessonReview {
            $review = $this->reviews->lock($review);

            if ($review->status !== StudentReviewStatus::Submitted) {
                return $review; // already decided (or never eligible for auto-action) — idempotent no-op
            }

            $snapshot = [
                'moderation_model' => $this->settings->moderation_model,
                'auto_publish_clean_reviews' => $this->settings->auto_publish_clean_reviews,
            ];

            // A Submitted review is by construction the "safe" branch of
            // Phase 17I's own risk split (unsafe content is Flagged at
            // submission, never Submitted) — every model here only
            // decides WHETHER to auto-publish that already-safe content.
            $shouldPublish = $this->settings->auto_publish_clean_reviews
                && in_array($this->settings->moderation_model, ['post_moderation', 'risk_based'], strict: true);

            if (! $shouldPublish) {
                // pre_moderation, or the auto-publish override is off —
                // leave it Submitted for a human. Still snapshot the
                // policy that was evaluated, for audit traceability.
                $review->fill(['moderation_snapshot' => $snapshot])->save();

                $this->audit->logSystem(
                    self::LOG_NAME,
                    'review_moderation_evaluated',
                    sprintf('Review %s evaluated automatically — held for manual moderation (%s).', $review->id, $this->settings->moderation_model),
                    $review,
                    ['lesson_id' => $review->lesson_id, 'moderation_model' => $this->settings->moderation_model],
                );

                return $review;
            }

            $review = $this->transition->execute($review, StudentReviewStatus::Published, [
                'moderated_at' => now()->toImmutable()->utc(),
                'moderated_by' => null,
                'moderation_reason' => 'auto_published_clean_review',
                'moderation_snapshot' => $snapshot,
            ]);

            $this->audit->logSystem(
                self::LOG_NAME,
                'review_auto_published',
                sprintf('Review %s auto-published under %s.', $review->id, $this->settings->moderation_model),
                $review,
                ['lesson_id' => $review->lesson_id, 'moderation_model' => $this->settings->moderation_model],
            );

            StudentReviewPublished::dispatch($review);

            return $review;
        });
    }
}
