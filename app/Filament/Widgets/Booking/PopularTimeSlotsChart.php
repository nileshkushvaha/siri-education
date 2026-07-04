<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Booking;

use App\Booking\Contracts\BookingAnalyticsServiceInterface;
use App\Models\Booking;
use Filament\Widgets\ChartWidget;

class PopularTimeSlotsChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Popular time slots (UTC, last 30 days)';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Booking::class) ?? false;
    }

    protected function getData(): array
    {
        $hours = app(BookingAnalyticsServiceInterface::class)->popularTimeSlots(
            now()->subDays(29)->startOfDay()->toImmutable(),
            now()->toImmutable(),
        );

        return [
            'datasets' => [
                [
                    'label' => 'Session starts',
                    'data' => $hours->pluck('bookings')->all(),
                    'backgroundColor' => '#06b6d4',
                    'borderRadius' => 6,
                ],
            ],
            'labels' => $hours
                ->pluck('hour')
                ->map(fn (int $hour): string => sprintf('%02d:00', $hour))
                ->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
