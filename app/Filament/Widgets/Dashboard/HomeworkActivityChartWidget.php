<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Dashboard;

/**
 * Optional Chart E — homework assigned against homework submitted.
 *
 * Two lines rather than bars because the insight is the *gap* between
 * them: work assigned but not handed in accumulates as a widening
 * space, which a bar chart hides. Both series come from
 * `LearningTrendsData`, each on its own authoritative timestamp
 * (`created_at` and `submitted_at`), gap-filled over the period.
 *
 * Rendered inside the learning section, and only for a viewer holding
 * `ViewLearningReports` — otherwise the composition never builds it.
 */
class HomeworkActivityChartWidget extends DashboardChartWidget
{
    protected ?string $maxHeight = '220px';

    protected function chartKey(): string
    {
        return 'homework_activity';
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
            'backgroundColor' => 'transparent',
            'fill' => false,
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
