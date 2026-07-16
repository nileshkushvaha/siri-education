<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Booking\Contracts\BookingAnalyticsServiceInterface;
use App\Filament\Support\CsvExport;
use App\Filament\Widgets\Booking\BookingKpiWidget;
use App\Filament\Widgets\Booking\BookingsPerDayChart;
use App\Filament\Widgets\Booking\BookingStatsWidget;
use App\Filament\Widgets\Booking\PopularSubjectsChart;
use App\Filament\Widgets\Booking\PopularTimeSlotsChart;
use App\Filament\Widgets\Booking\TeacherUtilizationWidget;
use App\Filament\Widgets\Booking\TopTeachersWidget;
use App\Models\Booking;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookingReports extends Page
{
    protected string $view = 'filament.pages.booking-reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Booking Reports';

    protected static ?string $title = 'Booking Reports';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Booking::class) ?? false;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            BookingKpiWidget::class,
            BookingStatsWidget::class,
            BookingsPerDayChart::class,
            PopularSubjectsChart::class,
            PopularTimeSlotsChart::class,
            TeacherUtilizationWidget::class,
            TopTeachersWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export KPIs (CSV)')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn (): StreamedResponse => $this->exportKpis()),
        ];
    }

    private function exportKpis(): StreamedResponse
    {
        $analytics = app(BookingAnalyticsServiceInterface::class);
        $from = now()->subDays(29)->startOfDay()->toImmutable();
        $to = now()->toImmutable();

        $kpis = $analytics->kpis($from, $to);

        $rows = collect([
            ['section' => 'KPI', 'metric' => 'Total bookings', 'value' => $kpis->totalBookings],
            ['section' => 'KPI', 'metric' => 'Demo requests', 'value' => $kpis->demoRequests],
            ['section' => 'KPI', 'metric' => 'Demo bookers', 'value' => $kpis->demoBookers],
            ['section' => 'KPI', 'metric' => 'Converted to paid', 'value' => $kpis->convertedBookers],
            ['section' => 'KPI', 'metric' => 'Conversion rate (%)', 'value' => $kpis->conversionRate],
            ['section' => 'KPI', 'metric' => 'Revenue', 'value' => $kpis->revenue],
            ['section' => 'KPI', 'metric' => 'Refunded', 'value' => $kpis->refunded],
            ['section' => 'KPI', 'metric' => 'Cancelled', 'value' => $kpis->cancelled],
            ['section' => 'KPI', 'metric' => 'Cancellation rate (%)', 'value' => $kpis->cancellationRate],
            ['section' => 'KPI', 'metric' => 'Completed', 'value' => $kpis->completed],
        ]);

        $rows = $rows
            ->concat($analytics->popularSubjects($from, $to)->map(fn (array $row): array => [
                'section' => 'Popular subjects',
                'metric' => $row['subject'],
                'value' => $row['bookings'],
            ]))
            ->concat($analytics->popularTimeSlots($from, $to)->filter(fn (array $row): bool => $row['bookings'] > 0)->map(fn (array $row): array => [
                'section' => 'Popular time slots (UTC)',
                'metric' => sprintf('%02d:00', $row['hour']),
                'value' => $row['bookings'],
            ]))
            ->concat($analytics->teacherUtilization($from, $to)->map(fn (array $row): array => [
                'section' => 'Teacher utilization (%)',
                'metric' => $row['teacher'],
                'value' => $row['utilization'],
            ]));

        return CsvExport::download($rows, [
            'Section' => 'section',
            'Metric' => 'metric',
            'Value' => 'value',
        ], sprintf('booking-kpis-%s.csv', now()->format('Y-m-d')));
    }
}
