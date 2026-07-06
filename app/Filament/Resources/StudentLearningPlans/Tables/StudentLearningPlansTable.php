<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningPlans\Tables;

use App\Enums\LearningPlanStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StudentLearningPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('student.name')->label('Student')->searchable()->sortable(),
                TextColumn::make('title')->searchable()->limit(40),
                TextColumn::make('subject.name')->searchable()->sortable(),
                TextColumn::make('primaryInstructor.name')->label('Instructor')->placeholder('Unassigned')->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (LearningPlanStatus $state): string => $state->label()),
                TextColumn::make('progress_percent')->suffix('%')->placeholder('0%'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(LearningPlanStatus::cases())->mapWithKeys(fn (LearningPlanStatus $status) => [$status->value => $status->label()])->all()),
                SelectFilter::make('subject_id')->relationship('subject', 'name')->searchable()->preload(),
                SelectFilter::make('primary_instructor_user_id')->label('Instructor')->relationship('primaryInstructor', 'name')->searchable()->preload(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('updated_at', 'desc');
    }
}
