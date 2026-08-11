<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Tables;

use App\Enums\AcademicStatus;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class EducationSystemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['countryMappings', 'academicLevelMappings', 'curriculumMappings']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Code')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AcademicStatus $state): string => $state->color()),
                TextColumn::make('country_mappings_count')
                    ->label('Countries')
                    ->counts('countryMappings'),
                TextColumn::make('academic_level_mappings_count')
                    ->label('Levels')
                    ->counts('academicLevelMappings'),
                TextColumn::make('curriculum_mappings_count')
                    ->label('Curricula')
                    ->counts('curriculumMappings'),
                TextColumn::make('display_order')
                    ->label('Order')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
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
            ->defaultSort('display_order');
    }
}
