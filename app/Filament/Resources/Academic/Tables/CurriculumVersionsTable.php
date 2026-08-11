<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Tables;

use App\Curriculum\Enums\CurriculumVersionStatus;
use App\Filament\Resources\Academic\CurriculumVersionResource;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CurriculumVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('curriculum')->withCount('modules'))
            ->columns([
                TextColumn::make('curriculum.name')
                    ->label('Curriculum')
                    ->searchable()
                    ->sortable(),
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
                    ->counts('modules'),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('curriculum_id')
                    ->label('Curriculum')
                    ->options(fn (): array => Curriculum::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('status')
                    ->options(collect(CurriculumVersionStatus::cases())->mapWithKeys(fn (CurriculumVersionStatus $s) => [$s->value => $s->label()])->all()),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (CurriculumVersion $record): string => CurriculumVersionResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
