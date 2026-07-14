<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\Quality\AlertQueueWidget;
use App\Filament\Widgets\Quality\HighlyRatedInstructorsWidget;
use App\Filament\Widgets\Quality\LowRatedInstructorsWidget;
use App\Filament\Widgets\Quality\ModerationQueueWidget;
use App\Filament\Widgets\Quality\QualityStatsWidget;
use App\Filament\Widgets\Quality\ReportQueueWidget;
use App\Quality\Support\QualityDashboardAccess;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Read-only administrator dashboard for review moderation workload,
 * review reports, instructor quality alerts, and instructor rating
 * health. Every action a section widget offers (moderate/resolve/
 * dismiss/assign) delegates to the existing
 * ReviewModerationService/ReviewReportService/
 * InstructorQualityAlertService — this page and its widgets never
 * write to a review/report/alert row directly.
 */
class ReviewsQualityDashboard extends Page
{
    protected string $view = 'filament.pages.reviews-quality-dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Reviews & Quality';

    protected static ?string $title = 'Reviews & Quality';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'reports/reviews-quality';

    public static function canAccess(): bool
    {
        return QualityDashboardAccess::userCan('ViewQualityDashboard');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Review moderation workload, reports, instructor quality alerts, and rating health.';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            QualityStatsWidget::class,
            ModerationQueueWidget::class,
            ReportQueueWidget::class,
            AlertQueueWidget::class,
            LowRatedInstructorsWidget::class,
            HighlyRatedInstructorsWidget::class,
        ];
    }
}
