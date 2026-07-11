<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorCompensationExceptions\Tables;

use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Enums\CompensationExceptionCategory;
use App\Models\InstructorCompensationException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Read-only queue + a single guarded "Retry now" action that re-runs
 * the normal idempotent earning creation (agreement resolved at the
 * lesson's scheduled start, kill switch still honored). No edit, no
 * delete, no way to fabricate an earning from here.
 */
class InstructorCompensationExceptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking_reference')
                    ->label('Booking / Lesson')
                    ->state(fn (InstructorCompensationException $record): string => $record->lesson?->metadata['booking_reference'] ?? substr($record->lesson_id, 0, 8).'…')
                    ->fontFamily('mono'),
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('scheduled_start_at')
                    ->label('Scheduled Lesson Time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (CompensationExceptionCategory $state): string => $state->label())
                    ->color(fn (CompensationExceptionCategory $state): string => $state->color()),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(60)
                    ->tooltip(fn (InstructorCompensationException $record): string => $record->reason),
                IconColumn::make('retry_eligible')
                    ->label('Retryable')
                    ->boolean(),
                TextColumn::make('attempt_count')
                    ->label('Attempts')
                    ->sortable(),
                TextColumn::make('first_failed_at')
                    ->label('First Failure')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_attempt_at')
                    ->label('Last Attempt')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('next_retry_at')
                    ->label('Next Auto-Retry')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('retry_exhausted_at')
                    ->label('Auto-Retry Exhausted')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('resolved_at')
                    ->label('Resolved')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(collect(CompensationExceptionCategory::cases())
                        ->mapWithKeys(fn (CompensationExceptionCategory $c) => [$c->value => $c->label()])
                        ->toArray()),
                TernaryFilter::make('resolved')
                    ->label('Resolved')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('resolved_at'),
                        false: fn ($query) => $query->whereNull('resolved_at'),
                    )
                    ->default(false),
                SelectFilter::make('instructor_id')
                    ->label('Instructor')
                    ->relationship('instructor', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry Now')
                    ->icon('heroicon-m-arrow-path')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('Re-runs the normal earning creation immediately, ignoring the automatic backoff/exhaustion schedule. The agreement is resolved at the lesson\'s scheduled start time; the earnings kill switch still applies.')
                    ->authorize(fn (InstructorCompensationException $record): bool => auth()->user()?->can('retry', $record) ?? false)
                    ->visible(fn (InstructorCompensationException $record): bool => $record->resolved_at === null && $record->retry_eligible)
                    ->action(function (InstructorCompensationException $record): void {
                        $lesson = $record->lesson;

                        if ($lesson === null) {
                            Notification::make()->title('Lesson no longer exists')->danger()->send();

                            return;
                        }

                        $earning = app(InstructorEarningServiceInterface::class)->createFromLesson($lesson);

                        $earning !== null
                            ? Notification::make()->title('Earning created — exception resolved')->success()->send()
                            : Notification::make()->title('Still blocked')->body($record->fresh()->reason)->warning()->send();
                    }),
            ])
            ->defaultSort('first_failed_at', 'desc');
    }
}
