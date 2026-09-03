<?php

namespace App\Filament\Resources\Academic\Tables;

use App\Enums\AcademicStatus;
use App\Filament\Support\Tables\AcademicStatusTabs;
use App\Filament\Support\Tables\AdminListTable;
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
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubjectsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category')->withCount(['topics', 'countries', 'teacherSubjects']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->placeholder('Uncategorised')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (AcademicStatus $state): string => $state->label())
                    ->color(fn (AcademicStatus $state): string => $state->color())
                    ->sortable(),
                AcademicStatusTabs::activeToggleColumn(),
                TextColumn::make('topics_count')
                    ->label('Topics')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('teacher_subjects_count')
                    ->label('Instructors')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('countries_count')
                    ->label('Countries')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn (?int $state): string => $state ? (string) $state : 'All'),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->since()
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
                    ->options(collect(AcademicStatus::cases())->mapWithKeys(fn (AcademicStatus $s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('country_scope')
                    ->label('Country availability')
                    ->options([
                        'all' => 'Available everywhere',
                        'restricted' => 'Restricted to specific countries',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'all' => $query->whereDoesntHave('countries'),
                        'restricted' => $query->whereHas('countries'),
                        default => $query,
                    }),
                SelectFilter::make('coverage')
                    ->label('Coverage')
                    ->options([
                        'no_topics' => 'No topics yet',
                        'no_instructors' => 'No instructors teach it',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'no_topics' => $query->whereDoesntHave('topics'),
                        'no_instructors' => $query->whereDoesntHave('teacherSubjects'),
                        default => $query,
                    }),
                TrashedFilter::make(),
            ])
            ->groups([
                Group::make('category.name')
                    ->label('Category')
                    ->getTitleFromRecordUsing(fn (Subject $record): string => $record->category?->name ?? 'Uncategorised')
                    ->collapsible(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    ...AcademicStatusTabs::bulkStatusActions('Subjects'),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No subjects yet')
            ->emptyStateDescription('Subjects are what students book and instructors teach. Add one to start building the catalogue.')
            ->reorderable('display_order')
            ->authorizeReorder(fn (): bool => auth()->user()?->can('update', new Subject) ?? false)
            ->defaultSort('display_order');

        return AdminListTable::apply($table, 'Search subjects');
    }
}
