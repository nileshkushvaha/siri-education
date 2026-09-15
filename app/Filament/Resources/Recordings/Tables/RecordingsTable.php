<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recordings\Tables;

use App\Booking\Enums\RecordingStatus;
use App\Filament\Resources\Recordings\Actions\RecordingActionGroup;
use App\Filament\Resources\Recordings\Support\RecordingStatePresenter;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\Recording;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only apart from the audited recovery and student-access actions.
 * No create, no edit, no delete: RecordingService is the only writer,
 * and a recording is never administratively deleted (only expired,
 * keeping its metadata as evidence).
 *
 * One primary row action (Details) and one overflow menu, so a row
 * never grows a horizontal strip of buttons. Every visibility decision
 * reads the row and the policy only — never a provider or storage API.
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
                    ->label('Student')
                    ->toggleable(),
                TextColumn::make('teacher.name')
                    ->label('Instructor')
                    ->toggleable(),
                TextColumn::make('provider'),
                TextColumn::make('storage_driver')
                    ->label('Storage')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === Recording::SOURCE_MANUAL ? 'Attached by operator' : 'Pipeline')
                    ->color(fn (?string $state): string => $state === Recording::SOURCE_MANUAL ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (RecordingStatus $state): string => $state->label())
                    ->color(fn (RecordingStatus $state): string => $state->color())
                    // Why the row is where it is, in one line — the stable
                    // failure label for failed rows, the lifecycle stage
                    // for the rest. Never a raw exception.
                    ->description(fn (Recording $record, RecordingStatePresenter $presenter): ?string => $presenter->failureLabel($record))
                    ->tooltip(fn (Recording $record, RecordingStatePresenter $presenter): string => $presenter->stateSummary($record)),
                TextColumn::make('capture_attempts')
                    ->label('Attempts')
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? gmdate('H:i:s', $state) : '—')
                    ->toggleable(),
                TextColumn::make('available_at')
                    ->label('Available')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(RecordingStatus::cases())->mapWithKeys(fn (RecordingStatus $s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('provider')
                    ->options(fn (): array => Recording::query()->distinct()->orderBy('provider')->pluck('provider', 'provider')->all()),
            ])
            ->recordActions([
                ViewAction::make()->label('Details'),
                RecordingActionGroup::make(),
            ])
            ->defaultSort('created_at', 'desc');

        return AdminListTable::apply($table);
    }
}
