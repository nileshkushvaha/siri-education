<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningPlans;

use App\Filament\Resources\StudentLearningPlans\Pages\CreateStudentLearningPlan;
use App\Filament\Resources\StudentLearningPlans\Pages\EditStudentLearningPlan;
use App\Filament\Resources\StudentLearningPlans\Pages\ListStudentLearningPlans;
use App\Filament\Resources\StudentLearningPlans\Schemas\StudentLearningPlanForm;
use App\Filament\Resources\StudentLearningPlans\Tables\StudentLearningPlansTable;
use App\Models\StudentLearningPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentLearningPlanResource extends Resource
{
    protected static ?string $model = StudentLearningPlan::class;

    protected static ?string $slug = 'student-learning-plans';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Learning Plans';

    protected static ?string $modelLabel = 'Student Learning Plan';

    protected static string|\UnitEnum|null $navigationGroup = 'Students';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return StudentLearningPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudentLearningPlansTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudentLearningPlans::route('/'),
            'create' => CreateStudentLearningPlan::route('/create'),
            'edit' => EditStudentLearningPlan::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['student', 'learningGoal', 'primaryInstructor', 'subject', 'academicLevel']);
    }
}
