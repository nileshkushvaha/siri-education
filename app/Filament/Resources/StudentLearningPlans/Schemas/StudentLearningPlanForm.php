<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningPlans\Schemas;

use App\Enums\LearningPlanStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class StudentLearningPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Plan')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('student_user_id')
                            ->label('Student')
                            ->relationship('student', 'name', fn (Builder $query) => $query->whereHas('roles', fn (Builder $q) => $q->where('name', 'student')))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->placeholder('Select a student')
                            ->disabledOn('edit')
                            ->dehydrated(false),
                        Select::make('learning_goal_id')
                            ->label('Learning Goal')
                            ->relationship('learningGoal', 'title')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->placeholder('Select a learning goal')
                            ->disabledOn('edit')
                            ->dehydrated(false),
                    ]),
                    Grid::make(2)->schema([
                        Select::make('primary_instructor_user_id')
                            ->label('Primary Instructor')
                            ->relationship('primaryInstructor', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Unassigned')
                            ->disabledOn('edit')
                            ->dehydrated(false)
                            ->helperText('Use the Assign Instructor action so eligibility and audit logging are enforced.'),
                        Select::make('status')
                            ->options(collect(LearningPlanStatus::cases())->mapWithKeys(fn (LearningPlanStatus $status) => [$status->value => $status->label()])->all())
                            ->required()
                            ->default(LearningPlanStatus::Draft->value)
                            ->placeholder('Select status')
                            ->disabledOn('edit')
                            ->dehydrated(false)
                            ->helperText('Use lifecycle actions so transitions are validated and logged.'),
                    ]),
                    Grid::make(2)->schema([
                        Select::make('subject_id')
                            ->relationship('subject', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->placeholder('Select a subject')
                            ->disabledOn('edit')
                            ->dehydrated(false),
                        Select::make('academic_level_id')
                            ->relationship('academicLevel', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Select an academic level')
                            ->disabledOn('edit')
                            ->dehydrated(false),
                    ]),
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('e.g. Algebra Mastery Plan')
                        ->disabledOn('edit')
                        ->dehydrated(false),
                    Textarea::make('summary')
                        ->columnSpanFull()
                        ->placeholder('Brief summary of this plan…')
                        ->disabledOn('edit')
                        ->dehydrated(false)
                        ->helperText('Use Adjust Plan to record the reason and previous/new values.'),
                    Grid::make(3)->schema([
                        TextInput::make('recommended_frequency')
                            ->maxLength(255)
                            ->placeholder('e.g. 2x per week')
                            ->disabledOn('edit')
                            ->dehydrated(false),
                        TextInput::make('recommended_lesson_duration_minutes')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(600)
                            ->placeholder('e.g. 60')
                            ->disabledOn('edit')
                            ->dehydrated(false),
                        TextInput::make('progress_percent')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->placeholder('0')
                            ->disabledOn('edit')
                            ->dehydrated(false)
                            ->helperText('Progress is calculated from milestones or service lifecycle actions.'),
                    ]),
                    Textarea::make('current_focus')
                        ->columnSpanFull()
                        ->placeholder('What the student is currently focused on…')
                        ->disabledOn('edit')
                        ->dehydrated(false),
                    DatePicker::make('target_completion_date')
                        ->placeholder('Select a target completion date')
                        ->disabledOn('edit')
                        ->dehydrated(false),
                ]),
        ]);
    }
}
