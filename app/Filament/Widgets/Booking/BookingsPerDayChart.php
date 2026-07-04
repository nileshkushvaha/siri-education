<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Booking;

use App\Booking\Contracts\BookingAnalyticsServiceInterface;
use App\Models\Booking;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class BookingsPerDayChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Bookings & revenue per day (last 30 days)';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Booking::class) ?? false;
    }

    protected function getData(): array
    {
        $trend = app(BookingAnalyticsServiceInterface::class)->trend(
            now()->subDays(29)->startOfDay()->toImmutable(),
            now()->toImmutable(),
        );

        return [
            'datasets' => [
                [
                    'label' => 'Bookings',
                    'data' => $trend->pluck('bookings')->all(),
                    'borderColor' => '#6366f1',
                    'backgroundColor' => 'rgba(99, 102, 241, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                    'yAxisID' => 'y',
                ],
                [
                    'label' => 'Revenue',
                    'data' => $trend->pluck('revenue')->all(),
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => false,
                    'tension' => 0.3,
                    'yAxisID' => 'revenue',
                ],
            ],
            'labels' => $trend
                ->pluck('day')
                ->map(fn (string $day): string => Carbon::parse($day)->format('M j'))
                ->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true, 'position' => 'left', 'ticks' => ['precision' => 0]],
                'revenue' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['drawOnChartArea' => false]],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
