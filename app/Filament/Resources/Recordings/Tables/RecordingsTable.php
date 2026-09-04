<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recordings\Tables;

use App\Booking\Enums\RecordingStatus;
use App\Filament\Resources\Recordings\Actions\DownloadRecordingAction;
use App\Filament\Resources\Recordings\Actions\RestoreStudentAccessAction;
use App\Filament\Resources\Recordings\Actions\RetryRecordingIngestionAction;
use App\Filament\Resources\Recordings\Actions\WithholdStudentAccessAction;
use App\Filament\Support\Tables\AdminListTable;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only apart from one audited recovery action. No create, no
 * edit, no delete: RecordingService is the only writer, and a
 * recording is never administratively deleted (only expired, keeping
 * its metadata as evidence).
 */
class RecordingsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('booking.reference')
                    ->label('Booking')
                    ->searchable(),
                TextColumn::make('student.name')
                    ->label('Student'),
                TextColumn::make('teacher.name')
                    ->label('Instructor'),
                TextColumn::make('provider'),
                TextColumn::make('storage_driver')
                    ->label('Storage')
                    ->placeholder('—')
                    ->toggleable(),
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
                DownloadRecordingAction::make(),
                WithholdStudentAccessAction::make(),
                RestoreStudentAccessAction::make(),
                RetryRecordingIngestionAction::make(),
            ])
            ->defaultSort('created_at', 'desc');

        return AdminListTable::apply($table);
    }
}
