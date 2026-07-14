<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Quality;

use App\Filament\Resources\Users\UserResource;
use App\Models\InstructorRatingAggregate;
use App\Quality\Support\QualityDashboardAccess;
use App\Settings\ReviewSettings;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Instructors whose current aggregate average is at or below the
 * dashboard's low-rating threshold, with at least the configured
 * minimum eligible review count (avoiding a misleading conclusion
 * from a single review). No numeric internal quality score is
 * computed or displayed anywhere here — only the same average/count
 * already public on the instructor's own profile (Phase 17L).
 */
class LowRatedInstructorsWidget extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return QualityDashboardAccess::userCan('ViewInstructorQualityAlerts');
    }

    public function table(Table $table): Table
    {
        $settings = app(ReviewSettings::class);

        return $table
            ->heading('Low-Rated Instructors')
            ->query(
                InstructorRatingAggregate::query()
                    ->with('instructor:id,name')
                    ->where('eligible_review_count', '>=', $settings->quality_dashboard_min_review_count)
                    ->whereRaw('(overall_rating_sum * 1.0 / eligible_review_count) <= ?', [$settings->quality_dashboard_low_rating_threshold])
                    ->orderByRaw('(overall_rating_sum * 1.0 / eligible_review_count) ASC'),
            )
            ->columns([
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->url(fn (InstructorRatingAggregate $record) => UserResource::getUrl('view', ['record' => $record->instructor_id])),
                TextColumn::make('average')
                    ->label('Average Rating')
                    ->getStateUsing(fn (InstructorRatingAggregate $record): string => number_format($record->overallAverage() ?? 0, 2)),
                TextColumn::make('eligible_review_count')
                    ->label('Eligible Reviews'),
            ])
            ->recordActions([])
            ->paginated([10, 25])
            ->defaultPaginationPageOption(10);
    }
}
