<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Booking;

use App\Booking\Contracts\BookingAnalyticsServiceInterface;
use App\Models\Booking;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Headline KPIs (last 30 days) from the cached analytics service. */
class BookingKpiWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Booking::class) ?? false;
    }

    protected function getStats(): array
    {
        // Widgets are Livewire components rebuilt per request — container
        // lookup instead of constructor injection.
        $kpis = app(BookingAnalyticsServiceInterface::class)->kpis(
            now()->subDays(29)->startOfDay()->toImmutable(),
            now()->toImmutable(),
        );

        return [
            Stat::make('Demo requests', (string) $kpis->demoRequests)
                ->description('Free demos booked, 30 days')
                ->icon('heroicon-o-sparkles')
                ->color('info'),
            Stat::make('Conversion rate', $kpis->conversionRate.'%')
                ->description(sprintf('%d of %d demo bookers went paid', $kpis->convertedBookers, $kpis->demoBookers))
                ->icon('heroicon-o-arrow-trending-up')
                ->color($kpis->conversionRate >= 25 ? 'success' : 'warning'),
            Stat::make('Revenue', number_format((float) $kpis->revenue, 2))
                ->description($kpis->refunded !== '0.00' ? number_format((float) $kpis->refunded, 2).' refunded' : 'No refunds')
                ->icon('heroicon-o-banknotes')
                ->color('success'),
            Stat::make('Cancellation rate', $kpis->cancellationRate.'%')
                ->description(sprintf('%d of %d bookings cancelled', $kpis->cancelled, $kpis->totalBookings))
                ->icon('heroicon-o-x-circle')
                ->color($kpis->cancellationRate > 20 ? 'danger' : 'success'),
        ];
    }
}
