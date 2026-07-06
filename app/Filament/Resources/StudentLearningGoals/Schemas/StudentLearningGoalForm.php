<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningGoals\Schemas;

use App\Enums\LearningGoalStatus;
use App\Enums\LearningGoalType;
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

class StudentLearningGoalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Goal')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('user_id')
                                ->label('Student')
                                ->relationship('user', 'name', fn (Builder $query) => $query->whereHas('roles', fn (Builder $q) => $q->where('name', 'student')))
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('subject_id')
                                ->label('Subject')
                                ->options(fn () => Subject::availableForAssignment()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->preload()
                                ->required(),
                        ]),
                        Grid::make(2)->schema([
                            Select::make('type')
                                ->options(collect(LearningGoalType::cases())->mapWithKeys(fn (LearningGoalType $type) => [$type->value => $type->label()])->all())
                                ->required(),
                            Select::make('academic_level_id')
                                ->label('Academic Level')
                                ->options(fn () => AcademicLevel::availableForAssignment()->orderBy('display_order')->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->preload(),
                        ]),
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        Grid::make(3)->schema([
                            DatePicker::make('target_date')
                                ->after('today'),
                            TextInput::make('priority')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(5),
                            Select::make('status')
                                ->options(collect(LearningGoalStatus::cases())->mapWithKeys(fn (LearningGoalStatus $status) => [$status->value => $status->label()])->all())
                                ->required()
                                ->default(LearningGoalStatus::Active->value),
                        ]),
                    ]),
            ]);
    }
}
