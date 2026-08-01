<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Dashboard;

/**
 * Chart B — daily new-student registrations.
 *
 * A line is correct here because the dimension is continuous time and
 * the series is already gap-filled by
 * `StudentEngagementRepository::registrationTrend()`, which zero-fills
 * every day in the period in the reporting timezone. A bar chart over
 * thirty days would read as noise.
 */
class StudentRegistrationChartWidget extends DashboardChartWidget
{
    protected function chartKey(): string
    {
        return 'student_registrations';
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function dataset(array $dataset): array
    {
        return [
            'label' => $dataset['label'],
            'data' => $dataset['data'],
            'borderColor' => $dataset['color'],
            'backgroundColor' => $this->areaFill($dataset['color']),
            'fill' => true,
            'tension' => 0.3,
            'pointRadius' => 0,
            'pointHoverRadius' => 5,
            'pointHoverBackgroundColor' => $dataset['color'],
            'pointHoverBorderColor' => '#ffffff',
            'pointHoverBorderWidth' => 2,
            'borderWidth' => 2.5,
        ];
    }

    protected function getOptions(): array
    {
        return $this->baseOptions();
    }
}
