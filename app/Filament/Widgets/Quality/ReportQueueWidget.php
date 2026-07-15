<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Quality;

use App\Filament\Resources\Users\UserResource;
use App\Models\ReviewReport;
use App\Quality\Support\QualityDashboardAccess;
use App\Reviews\Contracts\ReviewReportServiceInterface;
use App\Reviews\Enums\ReviewReportReason;
use App\Reviews\Enums\ReviewReportResolutionAction;
use App\Reviews\Enums\ReviewReportStatus;
use App\Reviews\Exceptions\InvalidReviewReportTransitionException;
use App\Reviews\Exceptions\ReviewValidationException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Review-report queue (Phase 17U.2 §9 — the previously-missing
 * mutation actions). Reporter identity is never a column here — the
 * reason, review, and instructor are enough to triage. Every row
 * action delegates exclusively to ReviewReportService, which itself
 * delegates any review-visibility change to ReviewModerationService —
 * this widget never writes a `status` column on either model.
 * Authorization is re-checked at execution time via `->authorize()`,
 * not just by hiding the button.
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
            ->recordActions([
                Action::make('startReview')
                    ->label('Start Review')
                    ->icon('heroicon-m-magnifying-glass')
                    ->color('gray')
                    ->authorize(fn (ReviewReport $record): bool => auth()->user()?->can('resolve', $record) ?? false)
                    ->visible(fn (ReviewReport $record): bool => $record->status === ReviewReportStatus::Pending)
                    ->requiresConfirmation()
                    ->action(fn (ReviewReport $record) => $this->resolve(
                        fn (ReviewReportServiceInterface $s) => $s->startReview($record, auth()->user()),
                        'Report review started',
                    )),
                Action::make('uphold')
                    ->label('Uphold')
                    ->icon('heroicon-m-shield-exclamation')
                    ->color('danger')
                    ->authorize(fn (ReviewReport $record): bool => auth()->user()?->can('resolve', $record) ?? false)
                    ->visible(fn (ReviewReport $record): bool => in_array($record->status, [ReviewReportStatus::Pending, ReviewReportStatus::UnderReview], true))
                    ->form([
                        Textarea::make('resolution_reason')->label('Reason')->required()->maxLength(500),
                        Select::make('action')
                            ->label('Action on the review')
                            ->options([
                                ReviewReportResolutionAction::NoAction->value => ReviewReportResolutionAction::NoAction->label(),
                                ReviewReportResolutionAction::HideReview->value => ReviewReportResolutionAction::HideReview->label(),
                                ReviewReportResolutionAction::RejectReview->value => ReviewReportResolutionAction::RejectReview->label(),
                                ReviewReportResolutionAction::ArchiveReview->value => ReviewReportResolutionAction::ArchiveReview->label(),
                            ])
                            ->default(ReviewReportResolutionAction::NoAction->value)
                            ->required()
                            ->native(false),
                    ])
                    ->action(fn (ReviewReport $record, array $data) => $this->resolve(
                        fn (ReviewReportServiceInterface $s) => $s->uphold($record, auth()->user(), $data['resolution_reason'], ReviewReportResolutionAction::from($data['action'])),
                        'Report upheld',
                    )),
                Action::make('dismiss')
                    ->label('Dismiss')
                    ->icon('heroicon-m-no-symbol')
                    ->color('gray')
                    ->authorize(fn (ReviewReport $record): bool => auth()->user()?->can('resolve', $record) ?? false)
                    ->visible(fn (ReviewReport $record): bool => in_array($record->status, [ReviewReportStatus::Pending, ReviewReportStatus::UnderReview], true))
                    ->form([
                        Textarea::make('resolution_reason')->label('Reason')->required()->maxLength(500),
                        Select::make('action')
                            ->label('Action on the review')
                            ->options([
                                ReviewReportResolutionAction::NoAction->value => ReviewReportResolutionAction::NoAction->label(),
                                ReviewReportResolutionAction::RestoreReview->value => ReviewReportResolutionAction::RestoreReview->label(),
                            ])
                            ->default(ReviewReportResolutionAction::NoAction->value)
                            ->required()
                            ->native(false),
                    ])
                    ->action(fn (ReviewReport $record, array $data) => $this->resolve(
                        fn (ReviewReportServiceInterface $s) => $s->dismiss($record, auth()->user(), $data['resolution_reason'], ReviewReportResolutionAction::from($data['action'])),
                        'Report dismissed',
                    )),
                Action::make('markDuplicate')
                    ->label('Mark Duplicate')
                    ->icon('heroicon-m-document-duplicate')
                    ->color('gray')
                    ->authorize(fn (ReviewReport $record): bool => auth()->user()?->can('resolve', $record) ?? false)
                    ->visible(fn (ReviewReport $record): bool => in_array($record->status, [ReviewReportStatus::Pending, ReviewReportStatus::UnderReview], true))
                    ->form([
                        Textarea::make('resolution_reason')->label('Reason (optional)')->maxLength(500),
                    ])
                    ->action(fn (ReviewReport $record, array $data) => $this->resolve(
                        fn (ReviewReportServiceInterface $s) => $s->markDuplicate($record, auth()->user(), filled($data['resolution_reason'] ?? null) ? $data['resolution_reason'] : null),
                        'Report marked duplicate',
                    )),
                Action::make('markRemainingDuplicate')
                    ->label('Mark Remaining Duplicate')
                    ->icon('heroicon-m-queue-list')
                    ->color('gray')
                    ->authorize(fn (ReviewReport $record): bool => auth()->user()?->can('resolve', $record) ?? false)
                    ->visible(fn (ReviewReport $record): bool => $record->status !== ReviewReportStatus::Duplicate)
                    ->form([
                        Textarea::make('resolution_reason')->label('Reason')->required()->maxLength(500)
                            ->helperText('Marks every other still-open (Pending/Under Review) report on this same review as Duplicate.'),
                    ])
                    ->action(fn (ReviewReport $record, array $data) => $this->resolve(
                        fn (ReviewReportServiceInterface $s) => $s->markRemainingPendingAsDuplicate($record->review, auth()->user(), $data['resolution_reason']),
                        'Remaining reports on this review marked duplicate',
                    )),
            ])
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }

    /** Converts domain failures into a friendly notification — never a raw exception reaching the panel. */
    private function resolve(callable $callback, string $successTitle): void
    {
        try {
            $callback(app(ReviewReportServiceInterface::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (InvalidReviewReportTransitionException|ReviewValidationException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        } catch (AuthorizationException $e) {
            Notification::make()->title('Not authorized')->body($e->getMessage())->danger()->send();
        }
    }
}
