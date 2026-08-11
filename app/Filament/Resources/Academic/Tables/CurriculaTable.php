<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Tables;

use App\Models\AcademicLevel;
use App\Models\Subject;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CurriculaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['subject', 'academicLevel'])->withCount('versions'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject.name')
                    ->label('Subject')
                    ->sortable(),
                TextColumn::make('academicLevel.name')
                    ->label('Academic Level')
                    ->sortable(),
                TextColumn::make('versions_count')
                    ->label('Versions')
                    ->counts('versions'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('subject_id')
                    ->label('Subject')
                    ->options(fn (): array => Subject::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('academic_level_id')
                    ->label('Academic Level')
                    ->options(fn (): array => AcademicLevel::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->defaultSort('name');
    }
}
