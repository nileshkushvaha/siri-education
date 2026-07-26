<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningGoals;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\StudentLearningGoals\Pages\CreateStudentLearningGoal;
use App\Filament\Resources\StudentLearningGoals\Pages\EditStudentLearningGoal;
use App\Filament\Resources\StudentLearningGoals\Pages\ListStudentLearningGoals;
use App\Filament\Resources\StudentLearningGoals\Schemas\StudentLearningGoalForm;
use App\Filament\Resources\StudentLearningGoals\Tables\StudentLearningGoalsTable;
use App\Models\StudentLearningGoal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentLearningGoalResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = StudentLearningGoal::class;

    protected static ?string $slug = 'student-learning-goals';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Learning Goals';

    protected static ?string $modelLabel = 'Student Learning Goal';

    protected static string|\UnitEnum|null $navigationGroup = 'Students';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return StudentLearningGoalForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudentLearningGoalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudentLearningGoals::route('/'),
            'create' => CreateStudentLearningGoal::route('/create'),
            'edit' => EditStudentLearningGoal::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'subject', 'academicLevel']);
    }
}
