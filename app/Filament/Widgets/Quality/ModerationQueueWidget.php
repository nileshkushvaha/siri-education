<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Quality;

use App\Filament\Resources\Users\UserResource;
use App\Models\LessonReview;
use App\Quality\Support\QualityDashboardAccess;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\ReviewableLessonType;
use App\Reviews\Enums\StudentReviewStatus;
use App\Reviews\Support\PublicReviewerIdentity;
use App\Settings\ReviewSettings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only review moderation queue. Row actions link out to the
 * existing moderation workflow (Filament's own review-detail route)
 * rather than mutating status inline — this widget never calls
 * ReviewModerationService itself.
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
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }
}
