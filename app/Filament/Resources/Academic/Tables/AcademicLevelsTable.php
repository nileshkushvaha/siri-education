<?php

namespace App\Filament\Resources\Academic\Tables;

use App\Enums\AcademicStatus;
use App\Filament\Support\Tables\AcademicStatusTabs;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\AcademicLevel;
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
use Illuminate\Database\Eloquent\Builder;

class AcademicLevelsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['studentProfiles', 'educationSystemMappings']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('grade_range')
                    ->label('Grade range')
                    ->state(fn (AcademicLevel $record): string => $record->min_grade === null && $record->max_grade === null
                        ? 'Any grade'
                        : "{$record->min_grade}–{$record->max_grade}"),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (AcademicStatus $state): string => $state->label())
                    ->color(fn (AcademicStatus $state): string => $state->color())
                    ->sortable(),
                AcademicStatusTabs::activeToggleColumn(),
                TextColumn::make('education_system_mappings_count')
                    ->label('Education systems')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('student_profiles_count')
                    ->label('Students')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(AcademicStatus::cases())->mapWithKeys(fn (AcademicStatus $s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('grade_range')
                    ->label('Grade range')
                    ->options([
                        'set' => 'Has a grade range',
                        'unset' => 'Any grade',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'set' => $query->where(fn (Builder $q) => $q->whereNotNull('min_grade')->orWhereNotNull('max_grade')),
                        'unset' => $query->whereNull('min_grade')->whereNull('max_grade'),
                        default => $query,
                    }),
                SelectFilter::make('usage')
                    ->label('Used by')
                    ->options([
                        'systems' => 'Mapped to an education system',
                        'unmapped' => 'Not mapped to any system',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'systems' => $query->whereHas('educationSystemMappings'),
                        'unmapped' => $query->whereDoesntHave('educationSystemMappings'),
                        default => $query,
                    }),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    ...AcademicStatusTabs::bulkStatusActions('Levels'),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No academic levels yet')
            ->emptyStateDescription('Levels are the education stages (Primary, Middle School, High School…) that subjects, prices and instructors are matched against.')
            ->reorderable('display_order')
            ->authorizeReorder(fn (): bool => auth()->user()?->can('update', new AcademicLevel) ?? false)
            ->defaultSort('display_order');

        return AdminListTable::apply($table, 'Search levels');
    }
}
