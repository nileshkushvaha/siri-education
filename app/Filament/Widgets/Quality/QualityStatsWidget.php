<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Quality;

use App\Quality\Contracts\AdminQualityDashboardServiceInterface;
use App\Quality\Support\QualityDashboardAccess;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Platform-wide quality-assurance overview — moderation/report/alert workload plus rating health. */
class QualityStatsWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    public static function canView(): bool
    {
        return QualityDashboardAccess::userCan('ViewReviewMetrics');
    }

    protected function getStats(): array
    {
        // Widgets are Livewire components rebuilt per request —
        // container lookup instead of constructor injection.
        $summary = app(AdminQualityDashboardServiceInterface::class)->summary();

        return [
            Stat::make('Awaiting moderation', (string) ($summary->submittedReviews + $summary->flaggedReviews))
                ->description(sprintf('%d submitted, %d flagged', $summary->submittedReviews, $summary->flaggedReviews))
                ->icon('heroicon-o-inbox-stack')
                ->color($summary->flaggedReviews > 0 ? 'warning' : 'info'),

            Stat::make('Published reviews', (string) $summary->publishedReviews)
                ->description(sprintf('%d hidden, %d rejected', $summary->hiddenReviews, $summary->rejectedReviews))
                ->icon('heroicon-o-star')
                ->color('success'),

            Stat::make('Open reports', (string) ($summary->pendingReports + $summary->reportsUnderReview))
                ->description(sprintf('%d pending, %d under review', $summary->pendingReports, $summary->reportsUnderReview))
                ->icon('heroicon-o-flag')
                ->color($summary->pendingReports > 0 ? 'warning' : 'info'),

            Stat::make('Open quality alerts', (string) ($summary->openAlerts + $summary->alertsUnderReview))
                ->description(sprintf('%d high, %d critical severity', $summary->highSeverityAlerts, $summary->criticalSeverityAlerts))
                ->icon('heroicon-o-exclamation-triangle')
                ->color($summary->criticalSeverityAlerts > 0 ? 'danger' : ($summary->highSeverityAlerts > 0 ? 'warning' : 'success')),

            Stat::make('Platform average rating', $summary->platformAverageRating !== null ? number_format($summary->platformAverageRating, 2) : 'No ratings yet')
                ->description(sprintf('%d instructor(s), %d eligible reviews', $summary->instructorsWithPublishedRatings, $summary->platformEligiblePublishedReviewCount))
                ->icon('heroicon-o-chart-bar')
                ->color('info'),

            Stat::make('Instructor no-shows (30d)', (string) $summary->instructorNoShowCount)
                ->description(sprintf('%d instructor-attributed cancellations', $summary->instructorAttributedCancellationCount))
                ->icon('heroicon-o-user-minus')
                ->color($summary->instructorNoShowCount > 0 ? 'warning' : 'success'),
        ];
    }
}
