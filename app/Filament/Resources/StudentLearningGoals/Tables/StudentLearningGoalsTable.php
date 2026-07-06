<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningGoals\Tables;

use App\Enums\LearningGoalStatus;
use App\Enums\LearningGoalType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StudentLearningGoalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('subject.name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (LearningGoalType $state): string => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (LearningGoalStatus $state): string => $state->label()),
                TextColumn::make('target_date')
                    ->date()
                    ->placeholder('No target'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(LearningGoalStatus::cases())->mapWithKeys(fn (LearningGoalStatus $status) => [$status->value => $status->label()])->all()),
                SelectFilter::make('type')
                    ->options(collect(LearningGoalType::cases())->mapWithKeys(fn (LearningGoalType $type) => [$type->value => $type->label()])->all()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
