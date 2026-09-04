<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherLeave\Tables;

use App\Filament\Support\CsvExport;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\TeacherUnavailability;
use App\Services\Instructor\InstructorTimeOffService;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class TeacherLeaveTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('teacher.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('duration')
                    ->state(fn (TeacherUnavailability $record): string => $record->starts_at->diffForHumans($record->ends_at, true)),
                TextColumn::make('timezone')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('reason')
                    ->placeholder('—')
                    ->limit(40),
            ])
            ->filters([
                SelectFilter::make('teacher_id')
                    ->label('Teacher')
                    ->relationship('teacher', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('current_or_upcoming')
                    ->label('Current or upcoming')
                    ->query(fn (Builder $query): Builder => $query->where('ends_at', '>', now()))
                    ->default(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->authorize(fn (TeacherUnavailability $record): bool => auth()->user()?->can('delete', $record) ?? false)
                    ->action(function (TeacherUnavailability $record): void {
                        try {
                            app(InstructorTimeOffService::class)->delete($record, auth()->user());
                            Notification::make()->title('Time off deleted.')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title('Unable to delete time off.')->body($e->validator->errors()->first())->danger()->send();
                        } catch (AuthorizationException) {
                            Notification::make()->title('You are not authorized to delete this time off.')->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('export')
                        ->label('Export CSV')
                        ->icon('heroicon-m-arrow-down-tray')
                        ->action(fn (Collection $records) => CsvExport::download($records->load('teacher'), [
                            'Teacher' => 'teacher.name',
                            // TZ-5 (TZ-AUD-024) — canonical UTC, ISO-8601, labelled. See BookingsTable.
                            'Starts (UTC)' => fn (TeacherUnavailability $l): string => $l->starts_at->utc()->toIso8601String(),
                            'Ends (UTC)' => fn (TeacherUnavailability $l): string => $l->ends_at->utc()->toIso8601String(),
                            'Timezone' => 'timezone',
                            'Reason' => 'reason',
                        ], 'teacher-leave.csv'))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->action(function (Collection $records): void {
                            $service = app(InstructorTimeOffService::class);
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
                                : Notification::make()->title('Time off deleted.')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('starts_at', 'desc');

        return AdminListTable::apply($table);
    }
}
