<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Booking;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Models\Booking;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BookingStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Booking::class) ?? false;
    }

    protected function getStats(): array
    {
        // One aggregated query instead of six count() calls
        $stats = Booking::query()->selectRaw(
            'SUM(DATE(starts_at) = ?) as today,
             SUM(status IN (?, ?) AND starts_at >= ?) as upcoming,
             SUM(status = ?) as pending,
             SUM(status = ? AND completed_at >= ?) as completed_month,
             SUM(status = ? AND cancelled_at >= ?) as cancelled_30d,
             SUM(CASE WHEN payment_status = ? THEN price ELSE 0 END) as revenue',
            [
                today()->toDateString(),
                BookingStatus::Pending->value, BookingStatus::Confirmed->value, now(),
                BookingStatus::Pending->value,
                BookingStatus::Completed->value, now()->startOfMonth(),
                BookingStatus::Cancelled->value, now()->subDays(30),
                BookingPaymentStatus::Paid->value,
            ],
        )->first();

        return [
            Stat::make('Today', (string) (int) $stats->today)
                ->description('Sessions starting today')
                ->icon('heroicon-o-calendar-days'),
            Stat::make('Upcoming', (string) (int) $stats->upcoming)
                ->description('Pending + confirmed ahead')
                ->color('info'),
            Stat::make('Awaiting approval', (string) (int) $stats->pending)
                ->color(((int) $stats->pending) > 0 ? 'warning' : 'success'),
            Stat::make('Completed this month', (string) (int) $stats->completed_month)
                ->color('success'),
            Stat::make('Cancelled (30d)', (string) (int) $stats->cancelled_30d)
                ->color('danger'),
            Stat::make('Revenue (paid)', number_format((float) $stats->revenue, 2))
                ->description('Sum of paid bookings')
                ->color('success'),
        ];
    }
}
