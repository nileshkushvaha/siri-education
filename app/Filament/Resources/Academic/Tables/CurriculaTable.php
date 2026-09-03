<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Tables;

use App\Curriculum\Enums\CurriculumVersionStatus;
use App\Filament\Support\Tables\AdminListTable;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CurriculaTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['subject', 'academicLevel'])
                ->withCount([
                    'versions',
                    'versions as published_versions_count' => fn (Builder $q) => $q->where('status', CurriculumVersionStatus::Published),
                ]))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('subject.name')
                    ->label('Subject')
                    ->sortable(),
                TextColumn::make('academicLevel.name')
                    ->label('Academic level')
                    ->sortable(),
                TextColumn::make('publication')
                    ->label('Publication')
                    ->badge()
                    ->state(fn ($record): string => match (true) {
                        $record->published_versions_count > 0 => 'Published',
                        $record->versions_count > 0 => 'Draft only',
                        default => 'No versions',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Published' => 'success',
                        'Draft only' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('versions_count')
                    ->label('Versions')
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
                SelectFilter::make('academic_level_id')
                    ->label('Academic level')
                    ->relationship('academicLevel', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('publication')
                    ->label('Publication')
                    ->options([
                        'published' => 'Has a published version',
                        'draft' => 'Draft versions only',
                        'none' => 'No versions yet',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'published' => $query->whereHas('versions', fn (Builder $q) => $q->where('status', CurriculumVersionStatus::Published)),
                        'draft' => $query->whereHas('versions')->whereDoesntHave('versions', fn (Builder $q) => $q->where('status', CurriculumVersionStatus::Published)),
                        'none' => $query->whereDoesntHave('versions'),
                        default => $query,
                    }),
                TrashedFilter::make(),
            ])
            ->groups([
                Group::make('subject.name')->label('Subject')->collapsible(),
                Group::make('academicLevel.name')->label('Academic level')->collapsible(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->emptyStateHeading('No curricula yet')
            ->emptyStateDescription('A curriculum ties a subject and level to versioned, structured content.')
            ->defaultSort('name');

        return AdminListTable::apply($table, 'Search curricula');
    }
}
