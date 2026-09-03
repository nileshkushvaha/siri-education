<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Tables;

use App\Curriculum\Enums\CurriculumVersionStatus;
use App\Filament\Resources\Academic\CurriculumVersionResource;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\CurriculumVersion;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CurriculumVersionsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('curriculum.subject')->withCount('modules'))
            ->columns([
                TextColumn::make('curriculum.name')
                    ->label('Curriculum')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (CurriculumVersion $record): ?string => $record->curriculum?->subject?->name),
                TextColumn::make('version_number')
                    ->label('Version')
                    ->formatStateUsing(fn (int $state): string => "v{$state}")
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (CurriculumVersionStatus $state): string => $state->color())
                    ->formatStateUsing(fn (CurriculumVersionStatus $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('modules_count')
                    ->label('Modules')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('published_at')
                    ->label('Published')
                    ->dateTime()
                    ->placeholder('Not published')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('curriculum_id')
                    ->label('Curriculum')
                    ->relationship('curriculum', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options(collect(CurriculumVersionStatus::cases())->mapWithKeys(fn (CurriculumVersionStatus $s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('modules')
                    ->label('Content')
                    ->options([
                        'with' => 'Has modules',
                        'empty' => 'No modules yet',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'with' => $query->whereHas('modules'),
                        'empty' => $query->whereDoesntHave('modules'),
                        default => $query,
                    }),
            ])
            ->groups([
                Group::make('curriculum.name')->label('Curriculum')->collapsible(),
                Group::make('status')
                    ->label('Status')
                    ->getTitleFromRecordUsing(fn (CurriculumVersion $record): string => $record->status->label())
                    ->collapsible(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (CurriculumVersion $record): string => CurriculumVersionResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading('No curriculum versions yet')
            ->emptyStateDescription('Versions are created from a curriculum. Open a curriculum and add its first version there.')
            ->defaultSort('updated_at', 'desc');

        return AdminListTable::apply($table, 'Search curricula');
    }
}
