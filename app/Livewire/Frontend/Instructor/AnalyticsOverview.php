<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Enums\InstructorAnalyticsPeriod;
use App\Services\Instructor\InstructorAnalyticsService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Read-only instructor analytics foundation plus Advanced
 * Performance Insights — same page, same component, one
 * shared period filter. Every figure comes from InstructorAnalyticsService,
 * scoped to auth()->user() only — never a request-supplied instructor
 * id. The period filter only changes which bounded aggregate queries
 * run; it never triggers a write.
 */
final class AnalyticsOverview extends Component
{
    public string $period = InstructorAnalyticsPeriod::Last30Days->value;

    public function setPeriod(string $period): void
    {
        // Validated against the enum itself — an invalid value is
        // simply ignored rather than trusted, matching Livewire's
        // general rule of never trusting a client-supplied string.
        if (InstructorAnalyticsPeriod::tryFrom($period) === null) {
            return;
        }

        $this->period = $period;
    }

    public function render(InstructorAnalyticsService $analytics): View
    {
        // Defensive: a tampered client value falls back to the default
        // rather than a thrown ValueError — this only ever selects
        // which aggregate queries run, never anything write-adjacent.
        $period = InstructorAnalyticsPeriod::tryFrom($this->period) ?? InstructorAnalyticsPeriod::Last30Days;

        $instructor = auth()->user();

        return view('livewire.frontend.instructor.analytics-overview', [
            'data' => $analytics->overview($instructor, $period),
            'insights' => $analytics->performanceInsights($instructor, $period),
            'periods' => InstructorAnalyticsPeriod::cases(),
        ]);
    }
}
