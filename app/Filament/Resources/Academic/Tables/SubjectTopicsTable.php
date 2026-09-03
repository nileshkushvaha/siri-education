<?php

namespace App\Filament\Resources\Academic\Tables;

use App\Enums\AcademicStatus;
use App\Filament\Support\Tables\AcademicStatusTabs;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\SubjectTopic;
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

class SubjectTopicsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['subject', 'parent'])->withCount(['instructorCoverage', 'children']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (SubjectTopic $record): ?string => $record->parent?->name ? 'Sub-topic of '.$record->parent->name : null),
                TextColumn::make('subject.name')
                    ->label('Subject')
                    ->sortable(),
                TextColumn::make('kind')
                    ->label('Type')
                    ->badge()
                    ->state(fn (SubjectTopic $record): string => $record->parent_id ? 'Sub-topic' : 'Topic')
                    ->color(fn (string $state): string => $state === 'Topic' ? 'primary' : 'gray'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (AcademicStatus $state): string => $state->label())
                    ->color(fn (AcademicStatus $state): string => $state->color())
                    ->sortable(),
                AcademicStatusTabs::activeToggleColumn(),
                TextColumn::make('children_count')
                    ->label('Sub-topics')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('instructor_coverage_count')
                    ->label('Instructors')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('subject_id')
                    ->label('Subject')
                    ->relationship('subject', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('kind')
                    ->label('Type')
                    ->options([
                        'topic' => 'Top-level topics',
                        'subtopic' => 'Sub-topics',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'topic' => $query->whereNull('parent_id'),
                        'subtopic' => $query->whereNotNull('parent_id'),
                        default => $query,
                    }),
                SelectFilter::make('status')
                    ->options(collect(AcademicStatus::cases())->mapWithKeys(fn (AcademicStatus $s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('coverage')
                    ->label('Instructor coverage')
                    ->options([
                        'covered' => 'Taught by at least one instructor',
                        'uncovered' => 'No instructor yet',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'covered' => $query->whereHas('instructorCoverage'),
                        'uncovered' => $query->whereDoesntHave('instructorCoverage'),
                        default => $query,
                    }),
                TrashedFilter::make(),
            ])
            ->groups([
                Group::make('subject.name')->label('Subject')->collapsible(),
                Group::make('parent.name')
                    ->label('Parent topic')
                    ->getTitleFromRecordUsing(fn (SubjectTopic $record): string => $record->parent?->name ?? 'Top-level topics')
                    ->collapsible(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    ...AcademicStatusTabs::bulkStatusActions('Topics'),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No topics yet')
            ->emptyStateDescription('Topics break a subject into teachable units (Algebra, Linear Equations…) that instructors declare coverage for.')
            ->reorderable('display_order')
            ->authorizeReorder(fn (): bool => auth()->user()?->can('update', new SubjectTopic) ?? false)
            ->defaultSort('display_order');

        return AdminListTable::apply($table, 'Search topics');
    }
}
