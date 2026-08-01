<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Dashboard;

/**
 * Chart C — bookings created per day.
 *
 * Renders as bars for a short period (fourteen days or fewer), where
 * individual days are the unit of interest, and as a line for longer
 * ranges, where the shape matters more than each day.
 *
 * The owning calculation (`BookingAnalyticsRepository::trendPerDay`)
 * produces one gap-filled series with no booking-type split, so this is
 * deliberately single-series rather than showing a demo/paid breakdown
 * the owner cannot supply. The demo/paid split is available — as a
 * period total, not per day — in the Operations report this chart links
 * to.
 */
class BookingsPerDayChartWidget extends DashboardChartWidget
{
    private const int BAR_THRESHOLD_DAYS = 14;

    protected function chartKey(): string
    {
        return 'bookings_per_day';
    }

    protected function getType(): string
    {
        return count($this->chart()?->labels ?? []) <= self::BAR_THRESHOLD_DAYS ? 'bar' : 'line';
    }

    protected function dataset(array $dataset): array
    {
        $isBar = $this->getType() === 'bar';

        return [
            'label' => $dataset['label'],
            'data' => $dataset['data'],
            'borderColor' => $dataset['color'],
            'backgroundColor' => $isBar ? $dataset['color'] : 'rgba(99, 102, 241, 0.12)',
            'fill' => ! $isBar,
            'tension' => 0.3,
            'pointRadius' => 0,
            'pointHoverRadius' => 4,
            'borderRadius' => $isBar ? 4 : 0,
        ];
    }

    protected function getOptions(): array
    {
        return $this->baseOptions();
    }
}
