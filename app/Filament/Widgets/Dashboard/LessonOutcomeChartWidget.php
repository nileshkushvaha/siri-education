<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Dashboard;

/**
 * Chart A — the composition of finalized lesson outcomes as a single
 * 100% stacked horizontal bar.
 *
 * One bar reads as a composition at a glance and stays legible with six
 * categories. A pie or donut would shrink exactly the segments that
 * matter — the small no-show and technical-issue slices — to
 * unreadable slivers.
 *
 * Segment order is fixed best-to-worst by the composition service and
 * is never sorted by size, so a shift in the mix is visible across page
 * loads rather than being masked by re-ordering.
 *
 * The visible legend beneath the chart (rendered by the page, from
 * `DashboardChart::$segments`) carries each outcome's label, count,
 * percentage and its own drill-down link, so the chart never relies on
 * colour alone to convey meaning.
 */
class LessonOutcomeChartWidget extends DashboardChartWidget
{
    protected ?string $maxHeight = '72px';

    /** Matches the compact bar so the skeleton does not reserve chart-sized space. */
    protected ?string $placeholderHeight = '72px';

    protected function chartKey(): string
    {
        return 'lesson_outcomes';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function dataset(array $dataset): array
    {
        return [
            'label' => $dataset['label'],
            'data' => $dataset['data'],
            'backgroundColor' => $dataset['color'],
            'borderWidth' => 0,
            'barThickness' => 34,
            'borderRadius' => 3,
            'borderSkipped' => false,
        ];
    }

    protected function getOptions(): array
    {
        $total = 0;

        foreach ($this->chart()?->segments ?? [] as $segment) {
            $total += (int) $segment['value'];
        }

        return [
            'maintainAspectRatio' => false,
            'indexAxis' => 'y',
            'plugins' => [
                // The page renders an accessible legend with counts and
                // percentages, so Chart.js's own swatch legend would be
                // duplicated noise.
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => [
                    'stacked' => true,
                    'beginAtZero' => true,
                    // Pinning the axis to the total is what makes the bar
                    // read as a true 100% composition rather than a
                    // partially filled track.
                    'max' => $total > 0 ? $total : 1,
                    'display' => false,
                    'grid' => ['display' => false],
                ],
                'y' => [
                    'stacked' => true,
                    'display' => false,
                    'grid' => ['display' => false],
                ],
            ],
        ];
    }
}
