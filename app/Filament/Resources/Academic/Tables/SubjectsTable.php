<?php

namespace App\Filament\Resources\Academic\Tables;

use App\Enums\AcademicStatus;
use App\Models\Subject;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class SubjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AcademicStatus $state): string => $state->color()),
                TextColumn::make('topics_count')
                    ->label('Topics')
                    ->counts('topics'),
                TextColumn::make('countries_count')
                    ->label('Countries')
                    ->counts('countries')
                    ->formatStateUsing(fn (?int $state): string => $state ? (string) $state : 'All'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('academic_category_id')
                    ->label('Category')
                    ->relationship('category', 'name')
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
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->reorderable('display_order')
            ->authorizeReorder(fn (): bool => auth()->user()?->can('update', new Subject) ?? false)
            ->defaultSort('display_order');
    }
}
