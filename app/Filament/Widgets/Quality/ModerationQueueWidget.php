<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Quality;

use App\Filament\Resources\Users\UserResource;
use App\Models\LessonReview;
use App\Quality\Support\QualityDashboardAccess;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\ReviewableLessonType;
use App\Reviews\Enums\StudentReviewStatus;
use App\Reviews\Exceptions\InvalidReviewTransitionException;
use App\Reviews\Exceptions\ReviewValidationException;
use App\Reviews\Support\PublicReviewerIdentity;
use App\Settings\ReviewSettings;
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
 * Review moderation queue (Phase 17U.2 §8 — the previously-missing
 * mutation actions). Every row action delegates exclusively to
 * ReviewModerationService — this widget never writes `status` itself.
 * Visibility per action is a UX convenience based on the review's
 * current status; the service's own state-machine guard
 * (TransitionReviewStatusAction) is the authoritative check, so a
 * stale button click still fails safely with a friendly notification
 * rather than a raw exception. Authorization is re-checked at
 * execution time via `->authorize()`, not just by hiding the button.
 */
class ModerationQueueWidget extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return QualityDashboardAccess::userCan('ViewReviewModerationQueue');
    }

    public function table(Table $table): Table
    {
        $identityMode = app(ReviewSettings::class)->public_review_identity_mode;

        return $table
            ->heading('Review Moderation Queue')
            ->query(
                LessonReview::query()
                    ->withCount('reports')
                    ->with(['instructor:id,name', 'student:id,first_name,status', 'student.profile:id,user_id,student_status'])
                    ->orderByDesc('submitted_at'),
            )
            ->columns([
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->searchable()
                    ->url(fn (LessonReview $record) => UserResource::getUrl('view', ['record' => $record->instructor_id])),
                TextColumn::make('student_label')
                    ->label('Student')
                    ->getStateUsing(fn (LessonReview $record): string => PublicReviewerIdentity::label($record->student, $identityMode)),
                TextColumn::make('review_mode')
                    ->label('Mode')
                    ->badge()
                    ->formatStateUsing(fn (LessonReviewEligibilityMode $state): string => $state->label()),
                TextColumn::make('overall_rating')
                    ->label('Rating')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (StudentReviewStatus $state): string => $state->label())
                    ->color(fn (StudentReviewStatus $state): string => $state->color()),
                TextColumn::make('reports_count')
                    ->label('Reports')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(StudentReviewStatus::cases())->mapWithKeys(fn (StudentReviewStatus $s) => [$s->value => $s->label()])),
                SelectFilter::make('review_mode')
                    ->label('Mode')
                    ->options(collect(LessonReviewEligibilityMode::cases())->mapWithKeys(fn (LessonReviewEligibilityMode $m) => [$m->value => $m->label()])),
                SelectFilter::make('instructor_id')
                    ->label('Instructor')
                    ->relationship('instructor', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('overall_rating')
                    ->label('Rating')
                    ->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5']),
                Filter::make('lesson_type')
                    ->form([
                        Select::make('lesson_type')
                            ->label('Lesson Type')
                            ->options(collect(ReviewableLessonType::cases())->mapWithKeys(fn (ReviewableLessonType $t) => [$t->value => $t->label()])),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['lesson_type'] ?? null,
                        fn (Builder $q, $type) => $q->whereHas('eligibility', fn (Builder $eq) => $eq->where('lesson_type', $type)),
                    )),
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
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->authorize(fn (LessonReview $record): bool => auth()->user()?->can('moderate', $record) ?? false)
                    ->visible(fn (LessonReview $record): bool => in_array($record->status, [StudentReviewStatus::Submitted, StudentReviewStatus::Flagged], true))
                    ->form([
                        Textarea::make('reason')->label('Reason (optional)')->maxLength(500),
                    ])
                    ->action(fn (LessonReview $record, array $data) => $this->moderate(
                        fn (ReviewModerationServiceInterface $s) => $s->approve($record, auth()->user(), filled($data['reason'] ?? null) ? $data['reason'] : null),
                        'Review approved',
                    )),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->authorize(fn (LessonReview $record): bool => auth()->user()?->can('moderate', $record) ?? false)
                    ->visible(fn (LessonReview $record): bool => in_array($record->status, [StudentReviewStatus::Submitted, StudentReviewStatus::Flagged, StudentReviewStatus::Private], true))
                    ->form([
                        Textarea::make('reason')->label('Reason')->required()->maxLength(500),
                    ])
                    ->action(fn (LessonReview $record, array $data) => $this->moderate(
                        fn (ReviewModerationServiceInterface $s) => $s->reject($record, auth()->user(), $data['reason']),
                        'Review rejected',
                    )),
                Action::make('hide')
                    ->label('Hide')
                    ->icon('heroicon-m-eye-slash')
                    ->color('warning')
                    ->authorize(fn (LessonReview $record): bool => auth()->user()?->can('hide', $record) ?? false)
                    ->visible(fn (LessonReview $record): bool => $record->status === StudentReviewStatus::Published)
                    ->form([
                        Textarea::make('reason')->label('Reason')->required()->maxLength(500),
                    ])
                    ->action(fn (LessonReview $record, array $data) => $this->moderate(
                        fn (ReviewModerationServiceInterface $s) => $s->hide($record, auth()->user(), $data['reason']),
                        'Review hidden',
                    )),
                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('success')
                    ->authorize(fn (LessonReview $record): bool => auth()->user()?->can('moderate', $record) ?? false)
                    ->visible(fn (LessonReview $record): bool => $record->status === StudentReviewStatus::Hidden)
                    ->form([
                        Textarea::make('reason')->label('Reason')->required()->maxLength(500),
                    ])
                    ->action(fn (LessonReview $record, array $data) => $this->moderate(
                        fn (ReviewModerationServiceInterface $s) => $s->restore($record, auth()->user(), $data['reason']),
                        'Review restored',
                    )),
                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-m-archive-box')
                    ->color('gray')
                    ->authorize(fn (LessonReview $record): bool => auth()->user()?->can('moderate', $record) ?? false)
                    ->visible(fn (LessonReview $record): bool => in_array($record->status, [StudentReviewStatus::Hidden, StudentReviewStatus::Private], true))
                    ->form([
                        Textarea::make('reason')->label('Reason')->required()->maxLength(500),
                    ])
                    ->action(fn (LessonReview $record, array $data) => $this->moderate(
                        fn (ReviewModerationServiceInterface $s) => $s->archive($record, auth()->user(), $data['reason']),
                        'Review archived',
                    )),
            ])
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }

    /** Converts domain failures into a friendly notification — never a raw exception reaching the panel. */
    private function moderate(callable $callback, string $successTitle): void
    {
        try {
            $callback(app(ReviewModerationServiceInterface::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (InvalidReviewTransitionException|ReviewValidationException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        } catch (AuthorizationException $e) {
            Notification::make()->title('Not authorized')->body($e->getMessage())->danger()->send();
        }
    }
}
