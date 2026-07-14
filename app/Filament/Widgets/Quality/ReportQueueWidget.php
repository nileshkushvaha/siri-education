<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Quality;

use App\Filament\Resources\Users\UserResource;
use App\Models\ReviewReport;
use App\Quality\Support\QualityDashboardAccess;
use App\Reviews\Enums\ReviewReportReason;
use App\Reviews\Enums\ReviewReportStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only review-report queue. Reporter identity is never a column
 * here — the reason, review, and instructor are enough to triage; row
 * resolution happens through the existing report-resolution workflow,
 * never inline from this widget.
 */
class ReportQueueWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return QualityDashboardAccess::userCan('ViewReviewReports');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Review Report Queue')
            ->query(
                ReviewReport::query()
                    ->with(['review' => fn ($q) => $q->withCount('reports')->with('instructor:id,name')])
                    ->orderByDesc('submitted_at'),
            )
            ->columns([
                TextColumn::make('review.instructor.name')
                    ->label('Instructor')
                    ->url(fn (ReviewReport $record) => $record->review?->instructor_id !== null
                        ? UserResource::getUrl('view', ['record' => $record->review->instructor_id])
                        : null),
                TextColumn::make('reason')
                    ->badge()
                    ->formatStateUsing(fn (ReviewReportReason $state): string => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ReviewReportStatus $state): string => $state->label())
                    ->color(fn (ReviewReportStatus $state): string => $state->color()),
                TextColumn::make('review.status')
                    ->label('Review Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? '—'),
                TextColumn::make('review.reports_count')
                    ->label('Reports on Review')
                    ->badge(),
                TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ReviewReportStatus::cases())->mapWithKeys(fn (ReviewReportStatus $s) => [$s->value => $s->label()])),
                SelectFilter::make('reason')
                    ->options(collect(ReviewReportReason::cases())->mapWithKeys(fn (ReviewReportReason $r) => [$r->value => $r->label()])),
                Filter::make('submitted_between')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')->label('From')->native(false),
                        DatePicker::make('until')->label('Until')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('submitted_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('submitted_at', '<=', $date))),
            ])
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }
}
