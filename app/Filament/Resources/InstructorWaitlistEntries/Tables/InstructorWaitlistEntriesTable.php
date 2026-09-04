<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorWaitlistEntries\Tables;

use App\Enums\WaitlistEntryStatus;
use App\Filament\Support\Tables\AdminListTable;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InstructorWaitlistEntriesTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->searchable(),
                TextColumn::make('student.name')
                    ->label('Student')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (WaitlistEntryStatus $state): string => $state->label())
                    ->color(fn (WaitlistEntryStatus $state): string => $state->color()),
                TextColumn::make('joined_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('notified_at')
                    ->dateTime()
                    ->placeholder('Never')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('fulfilled_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(WaitlistEntryStatus::cases())
                        ->mapWithKeys(fn (WaitlistEntryStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('joined_at', 'desc');

        return AdminListTable::apply($table);
    }
}
