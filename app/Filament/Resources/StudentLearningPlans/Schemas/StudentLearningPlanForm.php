<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningPlans\Schemas;

use App\Enums\InstructorStatus;
use App\Enums\LearningPlanStatus;
use App\Models\AcademicLevel;
use App\Models\Subject;
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
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('student_user_id')
                            ->label('Student')
                            ->relationship('student', 'name', fn (Builder $query) => $query->whereHas('roles', fn (Builder $q) => $q->where('name', 'student')))
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('learning_goal_id')
                            ->label('Learning Goal')
                            ->relationship('learningGoal', 'title')
                            ->searchable()
                            ->preload()
                            ->required(),
                    ]),
                    Grid::make(2)->schema([
                        Select::make('primary_instructor_user_id')
                            ->label('Primary Instructor')
                            ->relationship(
                                'primaryInstructor',
                                'name',
                                fn (Builder $query) => $query->whereHas('profile', fn (Builder $q) => $q->whereIn('instructor_status', InstructorStatus::bookableValues())),
                            )
                            ->searchable()
                            ->preload(),
                        Select::make('status')
                            ->options(collect(LearningPlanStatus::cases())->mapWithKeys(fn (LearningPlanStatus $status) => [$status->value => $status->label()])->all())
                            ->required()
                            ->default(LearningPlanStatus::Draft->value),
                    ]),
                    Grid::make(2)->schema([
                        Select::make('subject_id')
                            ->options(fn () => Subject::availableForAssignment()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('academic_level_id')
                            ->options(fn () => AcademicLevel::availableForAssignment()->orderBy('display_order')->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload(),
                    ]),
                    TextInput::make('title')->required()->maxLength(255),
                    Textarea::make('summary')->columnSpanFull(),
                    Grid::make(3)->schema([
                        TextInput::make('recommended_frequency')->maxLength(255),
                        TextInput::make('recommended_lesson_duration_minutes')->numeric()->minValue(1)->maxValue(600),
                        TextInput::make('progress_percent')->numeric()->minValue(0)->maxValue(100),
                    ]),
                    Textarea::make('current_focus')->columnSpanFull(),
                    DatePicker::make('target_completion_date'),
                ]),
        ]);
    }
}
