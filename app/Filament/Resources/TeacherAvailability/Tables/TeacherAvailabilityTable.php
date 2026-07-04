<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherAvailability\Tables;

use App\Booking\Enums\Weekday;
use App\Models\TeacherAvailability;
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
use Illuminate\Database\Eloquent\Collection;

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
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->authorize(fn (): bool => auth()->user()?->can('create', TeacherAvailability::class) ?? false)
                        ->action(function (Collection $records): void {
                            $records->each->update(['is_active' => true]);
                            Notification::make()->title('Windows activated')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->icon('heroicon-m-no-symbol')
                        ->color('warning')
                        ->authorize(fn (): bool => auth()->user()?->can('create', TeacherAvailability::class) ?? false)
                        ->action(function (Collection $records): void {
                            $records->each->update(['is_active' => false]);
                            Notification::make()->title('Windows deactivated')->warning()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('teacher.name');
    }
}
