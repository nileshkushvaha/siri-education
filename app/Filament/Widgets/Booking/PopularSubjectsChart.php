<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Booking;

use App\Booking\Contracts\BookingAnalyticsServiceInterface;
use App\Models\Booking;
use Filament\Widgets\ChartWidget;

class PopularSubjectsChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Popular subjects (last 30 days)';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Booking::class) ?? false;
    }

    protected function getData(): array
    {
        $subjects = app(BookingAnalyticsServiceInterface::class)->popularSubjects(
            now()->subDays(29)->startOfDay()->toImmutable(),
            now()->toImmutable(),
        );

        return [
            'datasets' => [
                [
                    'label' => 'Bookings',
                    'data' => $subjects->pluck('bookings')->all(),
                    'backgroundColor' => '#8b5cf6',
                    'borderRadius' => 6,
                ],
            ],
            'labels' => $subjects
                ->pluck('subject')
                ->map(fn (string $subject): string => ucwords(str_replace('_', ' ', $subject)))
                ->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
