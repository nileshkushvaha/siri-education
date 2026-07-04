<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherLeave\Tables;

use App\Filament\Support\CsvExport;
use App\Models\TeacherUnavailability;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class TeacherLeaveTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('export')
                        ->label('Export CSV')
                        ->icon('heroicon-m-arrow-down-tray')
                        ->action(fn (Collection $records) => CsvExport::download($records->load('teacher'), [
                            'Teacher' => 'teacher.name',
                            'Starts (UTC)' => fn (TeacherUnavailability $l): string => $l->starts_at->toDateTimeString(),
                            'Ends (UTC)' => fn (TeacherUnavailability $l): string => $l->ends_at->toDateTimeString(),
                            'Reason' => 'reason',
                        ], 'teacher-leave.csv'))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('starts_at', 'desc');
    }
}
