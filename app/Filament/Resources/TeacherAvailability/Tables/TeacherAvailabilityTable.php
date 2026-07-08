<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherAvailability\Tables;

use App\Booking\Enums\Weekday;
use App\Models\TeacherAvailability;
use App\Services\Instructor\InstructorAvailabilityService;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class TeacherAvailabilityTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('teacher.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('day_of_week')
                    ->label('Day')
                    ->badge()
                    ->formatStateUsing(fn (Weekday $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('start_time')
                    ->time('H:i'),
                TextColumn::make('end_time')
                    ->time('H:i'),
                TextColumn::make('timezone')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('effective_from')
                    ->date()
                    ->placeholder('Always')
                    ->toggleable(),
                TextColumn::make('effective_until')
                    ->date()
                    ->placeholder('No end')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
            ])
            ->filters([
                SelectFilter::make('teacher_id')
                    ->label('Teacher')
                    ->relationship('teacher', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('day_of_week')
                    ->label('Day')
                    ->options(collect(Weekday::cases())
                        ->mapWithKeys(fn (Weekday $d) => [$d->value => $d->label()])
                        ->toArray()),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->authorize(fn (TeacherAvailability $record): bool => auth()->user()?->can('delete', $record) ?? false)
                    ->action(function (TeacherAvailability $record): void {
                        try {
                            app(InstructorAvailabilityService::class)->delete($record, auth()->user());
                            Notification::make()->title('Availability window deleted.')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title('Unable to delete availability window.')->body($e->validator->errors()->first())->danger()->send();
                        } catch (AuthorizationException) {
                            Notification::make()->title('You are not authorized to delete this availability window.')->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->authorize(fn (): bool => auth()->user()?->can('create', TeacherAvailability::class) ?? false)
                        ->action(function (Collection $records): void {
                            $service = app(InstructorAvailabilityService::class);
                            $records->each(fn (TeacherAvailability $record): TeacherAvailability => $service->setActive($record, true, auth()->user()));
                            Notification::make()->title('Windows activated')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->icon('heroicon-m-no-symbol')
                        ->color('warning')
                        ->authorize(fn (): bool => auth()->user()?->can('create', TeacherAvailability::class) ?? false)
                        ->action(function (Collection $records): void {
                            $service = app(InstructorAvailabilityService::class);
                            $records->each(fn (TeacherAvailability $record): TeacherAvailability => $service->setActive($record, false, auth()->user()));
                            Notification::make()->title('Windows deactivated')->warning()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->action(function (Collection $records): void {
                            $service = app(InstructorAvailabilityService::class);
                            $actor = auth()->user();
                            $failures = 0;

                            foreach ($records as $record) {
                                try {
                                    $service->delete($record, $actor);
                                } catch (ValidationException|AuthorizationException) {
                                    $failures++;
                                }
                            }

                            $failures > 0
                                ? Notification::make()->title("Deleted with {$failures} failure(s).")->warning()->send()
                                : Notification::make()->title('Availability windows deleted.')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('teacher.name');
    }
}
