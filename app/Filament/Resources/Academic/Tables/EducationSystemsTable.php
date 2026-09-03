<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Tables;

use App\Enums\AcademicStatus;
use App\Filament\Support\Tables\AcademicStatusTabs;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\EducationSystem;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EducationSystemsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['countryMappings', 'academicLevelMappings', 'levels', 'curriculumMappings']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (EducationSystem $record): ?string => $record->code ?: null),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (AcademicStatus $state): string => $state->label())
                    ->color(fn (AcademicStatus $state): string => $state->color())
                    ->sortable(),
                AcademicStatusTabs::activeToggleColumn(),
                TextColumn::make('setup')
                    ->label('Setup')
                    ->badge()
                    ->state(fn (EducationSystem $record): string => match (true) {
                        $record->country_mappings_count === 0 => 'Needs countries',
                        $record->academic_level_mappings_count === 0 => 'Needs levels',
                        $record->levels_count === 0 => 'Needs classes',
                        $record->curriculum_mappings_count === 0 => 'Needs curricula',
                        default => 'Complete',
                    })
                    ->color(fn (string $state): string => $state === 'Complete' ? 'success' : 'warning'),
                TextColumn::make('country_mappings_count')
                    ->label('Countries')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('academic_level_mappings_count')
                    ->label('Levels')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('levels_count')
                    ->label('Classes')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('curriculum_mappings_count')
                    ->label('Curricula')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(AcademicStatus::cases())->mapWithKeys(fn (AcademicStatus $s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('setup')
                    ->label('Setup')
                    ->options([
                        'complete' => 'Fully configured',
                        'no_countries' => 'Missing countries',
                        'no_levels' => 'Missing levels',
                        'no_classes' => 'Missing classes',
                        'no_curricula' => 'Missing curricula',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'complete' => $query->whereHas('countryMappings')->whereHas('academicLevelMappings')->whereHas('levels')->whereHas('curriculumMappings'),
                        'no_countries' => $query->whereDoesntHave('countryMappings'),
                        'no_levels' => $query->whereDoesntHave('academicLevelMappings'),
                        'no_classes' => $query->whereDoesntHave('levels'),
                        'no_curricula' => $query->whereDoesntHave('curriculumMappings'),
                        default => $query,
                    }),
                SelectFilter::make('country')
                    ->label('Country')
                    ->relationship('countryMappings.country', 'name')
                    ->searchable()
                    ->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    ...AcademicStatusTabs::bulkStatusActions('Education systems'),
                ]),
            ])
            ->emptyStateHeading('No education systems yet')
            ->emptyStateDescription('An education system (CBSE, IB, UK National Curriculum…) maps countries, levels and classes to curricula.')
            ->reorderable('display_order')
            ->authorizeReorder(fn (): bool => auth()->user()?->can('update', new EducationSystem) ?? false)
            ->defaultSort('display_order');

        return AdminListTable::apply($table, 'Search education systems');
    }
}
