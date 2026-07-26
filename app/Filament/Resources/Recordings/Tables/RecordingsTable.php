<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recordings\Tables;

use App\Booking\Enums\RecordingStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Read-only — no record actions besides View (requirement #6: no admin write path here). */
class RecordingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking.reference')
                    ->label('Booking')
                    ->searchable(),
                TextColumn::make('student.name')
                    ->label('Student'),
                TextColumn::make('teacher.name')
                    ->label('Instructor'),
                TextColumn::make('provider'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (RecordingStatus $state): string => $state->label())
                    ->color(fn (RecordingStatus $state): string => $state->color()),
                TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? gmdate('H:i:s', $state) : '—'),
                TextColumn::make('available_at')
                    ->label('Available')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(RecordingStatus::cases())->mapWithKeys(fn (RecordingStatus $s) => [$s->value => $s->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
