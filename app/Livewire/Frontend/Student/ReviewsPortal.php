<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\ReviewTag;
use App\Reviews\Contracts\StudentReviewEditingServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\EditStudentReviewData;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\LessonReviewEligibilityStatus;
use App\Reviews\Exceptions\ReviewEligibilityException;
use App\Reviews\Exceptions\ReviewValidationException;
use App\Settings\ReviewSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Student review portal (Phase 17R) — open opportunities, submitted
 * reviews with statuses, and limited editing. Everything is scoped to
 * auth()->user(); submission goes through the existing Phase 17I
 * StudentReviewService and editing through StudentReviewEditingService
 * — no validation, sanitization, eligibility, moderation, or aggregate
 * logic lives here. Forms are rendered from either current settings
 * (submission) or the review's own stored policy snapshot (editing).
 */
final class ReviewsPortal extends Component
{
    /** Eligibility currently being reviewed (form open); null = closed. */
    public ?string $submittingEligibilityId = null;

    /** Review currently being edited (form open); null = closed. */
    public ?string $editingReviewId = null;

    public int $overall_rating = 5;

    public ?int $teaching_quality_rating = null;

    public ?int $communication_rating = null;

    public ?int $punctuality_rating = null;

    public ?int $preparedness_rating = null;

    public ?int $learning_value_rating = null;

    public string $content = '';

    /** @var list<string> */
    public array $selectedTags = [];

    public function startSubmit(string $eligibilityId): void
    {
        $this->ownOpenEligibility($eligibilityId); // 404s a foreign/closed id
        $this->resetForm();
        $this->submittingEligibilityId = $eligibilityId;
    }

    public function submitReview(StudentReviewServiceInterface $reviews): void
    {
        $this->validateForm();

        $eligibility = $this->ownOpenEligibility($this->submittingEligibilityId ?? '');

        try {
            $reviews->submit($eligibility, auth()->user(), new SubmitStudentReviewData(
                overallRating: $this->overall_rating,
                teachingQualityRating: $this->teaching_quality_rating,
                communicationRating: $this->communication_rating,
                punctualityRating: $this->punctuality_rating,
                preparednessRating: $this->preparedness_rating,
                learningValueRating: $this->learning_value_rating,
                content: $this->content !== '' ? $this->content : null,
                tagKeys: $this->selectedTags,
            ));
        } catch (AuthorizationException|ReviewEligibilityException|ReviewValidationException $e) {
            $this->addError('form', $e->getMessage() ?: 'This review could not be submitted.');

            return;
        }

        $this->resetForm();
        $this->submittingEligibilityId = null;
        session()->flash('reviews-status', 'Thank you — your review was submitted.');
    }

    public function startEdit(string $reviewId, StudentReviewEditingServiceInterface $editing): void
    {
        $review = $this->ownReview($reviewId);

        if (! $editing->editabilityFor($review, auth()->user())->editable) {
            $this->addError('form', 'This review cannot currently be edited.');

            return;
        }

        $this->resetForm();
        $this->overall_rating = (int) $review->overall_rating;
        $this->teaching_quality_rating = $review->teaching_quality_rating;
        $this->communication_rating = $review->communication_rating;
        $this->punctuality_rating = $review->punctuality_rating;
        $this->preparedness_rating = $review->preparedness_rating;
        $this->learning_value_rating = $review->learning_value_rating;
        $this->content = (string) $review->content;
        $this->selectedTags = array_values(array_filter(array_map(
            fn (array $tag): ?string => $tag['key'] ?? null,
            $review->tags ?? [],
        )));
        $this->editingReviewId = $reviewId;
    }

    public function saveEdit(StudentReviewEditingServiceInterface $editing): void
    {
        $this->validateForm();

        $review = $this->ownReview($this->editingReviewId ?? '');

        try {
            $editing->edit($review, auth()->user(), new EditStudentReviewData(
                overallRating: $this->overall_rating,
                teachingQualityRating: $this->teaching_quality_rating,
                communicationRating: $this->communication_rating,
                punctualityRating: $this->punctuality_rating,
                preparednessRating: $this->preparedness_rating,
                learningValueRating: $this->learning_value_rating,
                content: $this->content !== '' ? $this->content : null,
                tagKeys: $this->selectedTags,
            ));
        } catch (AuthorizationException|ReviewEligibilityException|ReviewValidationException $e) {
            $this->addError('form', $e->getMessage() ?: 'This review could not be edited.');

            return;
        }

        $this->resetForm();
        $this->editingReviewId = null;
        session()->flash('reviews-status', 'Your review was updated. Edited reviews are re-checked before publication.');
    }

    public function cancelForm(): void
    {
        $this->resetForm();
        $this->submittingEligibilityId = null;
        $this->editingReviewId = null;
    }

    public function render(StudentReviewEditingServiceInterface $editing, ReviewSettings $settings): View
    {
        $studentId = (int) auth()->id();

        $eligibilities = LessonReviewEligibility::query()
            ->forStudent($studentId)
            ->with(['instructor', 'lesson'])
            ->orderByDesc('opens_at')
            ->get();

        $reviews = LessonReview::query()
            ->where('student_id', $studentId)
            ->with(['instructor', 'lesson'])
            ->withCount('revisions')
            ->orderByDesc('submitted_at')
            ->get();

        $user = auth()->user();

        return view('livewire.frontend.student.reviews-portal', [
            'openEligibilities' => $eligibilities->filter(
                fn (LessonReviewEligibility $e): bool => $e->status === LessonReviewEligibilityStatus::Open && now()->isBefore($e->expires_at),
            )->values(),
            'closedEligibilities' => $eligibilities->reject(
                fn (LessonReviewEligibility $e): bool => $e->status === LessonReviewEligibilityStatus::Open && now()->isBefore($e->expires_at),
            )->reject(
                fn (LessonReviewEligibility $e): bool => $e->status === LessonReviewEligibilityStatus::Used,
            )->values(),
            'reviews' => $reviews,
            'editability' => $reviews->mapWithKeys(
                fn (LessonReview $review) => [$review->id => $editing->editabilityFor($review, $user)],
            ),
            'tagOptions' => $this->tagOptions($eligibilities, $reviews),
            'ratingMin' => $settings->rating_min,
            'ratingMax' => $settings->rating_max,
            'dimensionsEnabled' => $settings->rating_dimensions_enabled,
        ]);
    }

    private function validateForm(): void
    {
        $this->validate([
            'overall_rating' => ['required', 'integer'],
            'teaching_quality_rating' => ['nullable', 'integer'],
            'communication_rating' => ['nullable', 'integer'],
            'punctuality_rating' => ['nullable', 'integer'],
            'preparedness_rating' => ['nullable', 'integer'],
            'learning_value_rating' => ['nullable', 'integer'],
            'content' => ['nullable', 'string', 'max:10000'],
            'selectedTags' => ['array'],
            'selectedTags.*' => ['string'],
        ]);
    }

    /** Ownership-scoped, Open-only lookup — a foreign or closed id 404s. */
    private function ownOpenEligibility(string $eligibilityId): LessonReviewEligibility
    {
        return LessonReviewEligibility::query()
            ->forStudent((int) auth()->id())
            ->where('status', LessonReviewEligibilityStatus::Open)
            ->findOrFail($eligibilityId);
    }

    /** Ownership-scoped lookup — a foreign id 404s instead of leaking. */
    private function ownReview(string $reviewId): LessonReview
    {
        return LessonReview::query()
            ->where('student_id', (int) auth()->id())
            ->findOrFail($reviewId);
    }

    /**
     * Active tags grouped by mode value, for whichever form is open.
     *
     * @return array<string, list<array{key: string, label: string}>>
     */
    private function tagOptions($eligibilities, $reviews): array
    {
        $modes = $eligibilities->pluck('eligibility_mode')
            ->merge($reviews->pluck('review_mode'))
            ->filter()
            ->unique();

        $options = [];

        foreach ($modes as $mode) {
            $options[$mode->value] = ReviewTag::query()
                ->active()
                ->applicableTo($mode)
                ->orderBy('sort_order')
                ->get(['key', 'label'])
                ->map(fn (ReviewTag $tag): array => ['key' => $tag->key, 'label' => $tag->label])
                ->all();
        }

        return $options;
    }

    private function resetForm(): void
    {
        $this->reset([
            'overall_rating', 'teaching_quality_rating', 'communication_rating',
            'punctuality_rating', 'preparedness_rating', 'learning_value_rating',
            'content', 'selectedTags',
        ]);
        $this->resetErrorBag();
    }
}
