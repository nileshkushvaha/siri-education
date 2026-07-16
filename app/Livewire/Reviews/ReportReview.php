<?php

declare(strict_types=1);

namespace App\Livewire\Reviews;

use App\Models\LessonReview;
use App\Reviews\Contracts\ReviewReportServiceInterface;
use App\Reviews\DTOs\SubmitReviewReportData;
use App\Reviews\Enums\ReviewReportReason;
use App\Reviews\Exceptions\DuplicateReviewReportException;
use App\Reviews\Exceptions\ReviewNotReportableException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Phase 18A — the sole user-facing entry point to the existing Phase
 * 17M Review Reporting backend. Authorization, reason validation,
 * duplicate prevention, explanation sanitization, audit logging, and
 * every domain rule are enforced exclusively by
 * ReviewReportServiceInterface::submit() (→ SubmitReviewReportAction);
 * this component only collects the two inputs the service accepts and
 * presents its outcome. It never creates a ReviewReport, changes review
 * status, or touches rating aggregates/quality alerts directly.
 */
final class ReportReview extends Component
{
    public string $reviewId;

    public string $selectedReason = '';

    public string $explanation = '';

    public ?string $successMessage = null;

    public function mount(string $reviewId): void
    {
        $this->reviewId = $reviewId;
    }

    /** Opening the form re-authorizes fresh — a stale "reportable" render never grants access on its own. */
    public function openModal(): void
    {
        $review = $this->reviewOrFail();

        Gate::authorize('report', $review);

        $this->reset(['selectedReason', 'explanation']);
        $this->successMessage = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', id: $this->modalId());
    }

    public function cancel(): void
    {
        $this->reset(['selectedReason', 'explanation']);
        $this->resetErrorBag();
        $this->dispatch('close-modal', id: $this->modalId());
    }

    public function submitReport(ReviewReportServiceInterface $reports): void
    {
        $this->validate([
            'selectedReason' => ['required', Rule::enum(ReviewReportReason::class)],
            'explanation' => ['nullable', 'string', 'max:1000'],
        ]);

        $review = $this->reviewOrFail();

        try {
            $reports->submit($review, auth()->user(), new SubmitReviewReportData(
                reason: ReviewReportReason::from($this->selectedReason),
                explanation: $this->explanation !== '' ? $this->explanation : null,
            ));
        } catch (AuthorizationException) {
            $this->addError('form', 'You are not able to report this review.');

            return;
        } catch (DuplicateReviewReportException) {
            // Neutral message only — never the report's status,
            // resolution, assigned admin, or other reporters (Phase
            // 18A §10). The raw exception message names the review id
            // and reason, which is never shown to the user.
            $this->addError('form', 'You have already reported this review.');

            return;
        } catch (ReviewNotReportableException) {
            // Neutral message only — never the review's actual
            // status/mode, which the raw exception message exposes
            // (Phase 18A §6: internal review statuses are never shown).
            $this->addError('form', 'This review can no longer be reported.');

            return;
        }

        $this->reset(['selectedReason', 'explanation']);
        $this->resetErrorBag();
        $this->successMessage = 'Thank you — this review has been reported and will be reviewed by our team.';
        $this->dispatch('close-modal', id: $this->modalId());
    }

    public function render(): View
    {
        $review = auth()->check() ? LessonReview::query()->find($this->reviewId) : null;

        return view('livewire.reviews.report-review', [
            'canReport' => $review !== null && auth()->user()->can('report', $review),
            'reasons' => ReviewReportReason::cases(),
            'modalId' => $this->modalId(),
        ]);
    }

    private function reviewOrFail(): LessonReview
    {
        return LessonReview::query()->findOrFail($this->reviewId);
    }

    private function modalId(): string
    {
        return 'report-review-modal-'.$this->reviewId;
    }
}
