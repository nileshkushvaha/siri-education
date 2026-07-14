<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Reviews\Contracts\InstructorQualityInsightsServiceInterface;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only — every value comes straight from
 * InstructorQualityInsightsService, scoped to `auth()->user()` only
 * (never a request-supplied instructor id). Recomputed fresh on every
 * render rather than cached on public properties, so pagination
 * clicks (via WithPagination's `page` query-string binding) always
 * see current data without any DTO-serialization concerns.
 */
final class QualityInsightsOverview extends Component
{
    use WithPagination;

    public function render(): View
    {
        $instructor = auth()->user();
        $service = app(InstructorQualityInsightsServiceInterface::class);

        return view('livewire.frontend.instructor.quality-insights-overview', [
            'insights' => $service->insightsFor($instructor),
            'reviews' => $service->recentReviewsFor($instructor),
        ]);
    }
}
