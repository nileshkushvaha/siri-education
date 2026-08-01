<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Dashboard;

/**
 * Chart D — active instructors beside booking demand, for the same five
 * subjects.
 *
 * Grouped horizontal bars: two measures on one categorical axis is
 * exactly what grouped bars are for, and horizontal keeps long subject
 * names legible without rotating labels.
 *
 * This is NOT an instructor-utilization chart, and it shows no ranking
 * or composite gap score — the marketplace report compares supply and
 * demand on compatible dimensions only, and inventing a score here
 * would be a metric the registry does not define.
 */
class SupplyDemandChartWidget extends DashboardChartWidget
{
    protected ?string $maxHeight = '280px';

    protected function chartKey(): string
    {
        return 'supply_demand';
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
            'borderRadius' => 4,
            'borderWidth' => 0,
        ];
    }

    protected function getOptions(): array
    {
        return [
            ...$this->baseOptions(),
            'indexAxis' => 'y',
            'scales' => [
                'x' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                    'grid' => ['color' => 'rgba(148, 163, 184, 0.18)'],
                ],
                'y' => [
                    'grid' => ['display' => false],
                ],
            ],
        ];
    }
}
