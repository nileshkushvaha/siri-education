<?php

namespace App\Filament\Resources\Academic\Tables;

use App\Enums\AcademicStatus;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class SubjectTopicsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['subject', 'parent']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject.name')
                    ->label('Subject')
                    ->sortable(),
                TextColumn::make('parent.name')
                    ->label('Parent Topic')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AcademicStatus $state): string => $state->color()),
                TextColumn::make('instructor_coverage_count')
                    ->label('Instructors')
                    ->counts('instructorCoverage'),
                TextColumn::make('display_order')
                    ->label('Order')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('subject_id')
                    ->label('Subject')
                    ->relationship('subject', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options(
                        collect(AcademicStatus::cases())
                            ->mapWithKeys(fn (AcademicStatus $s) => [$s->value => $s->label()])
                            ->toArray()
                    ),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->label('Activate selected')
                        ->icon('heroicon-m-check-circle')
                        ->action(function (Collection $records): void {
                            $records->each->update(['status' => AcademicStatus::Active]);
                            Notification::make()->title('Topics activated')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->label('Deactivate selected')
                        ->icon('heroicon-m-x-circle')
                        ->color('warning')
                        ->action(function (Collection $records): void {
                            $records->each->update(['status' => AcademicStatus::Inactive]);
                            Notification::make()->title('Topics deactivated')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('display_order');
    }
}
