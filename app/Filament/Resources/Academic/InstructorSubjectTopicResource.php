<?php

namespace App\Filament\Resources\Academic;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Academic\Pages\CreateInstructorSubjectTopic;
use App\Filament\Resources\Academic\Pages\EditInstructorSubjectTopic;
use App\Filament\Resources\Academic\Pages\ListInstructorSubjectTopics;
use App\Filament\Resources\Academic\Schemas\InstructorSubjectTopicForm;
use App\Filament\Resources\Academic\Tables\InstructorSubjectTopicsTable;
use App\Models\InstructorSubjectTopic;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class InstructorSubjectTopicResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = InstructorSubjectTopic::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Instructor Topic Coverage';

    protected static ?string $modelLabel = 'Instructor Topic Coverage';

    protected static ?string $pluralModelLabel = 'Instructor Topic Coverage';

    protected static string|\UnitEnum|null $navigationGroup = 'Academic';

    protected static ?int $navigationSort = 9;

    protected static bool $shouldRegisterNavigation = false;

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record instanceof InstructorSubjectTopic) {
            return null;
        }

        $record->loadMissing(['teacher', 'topic.subject', 'academicLevel']);

        return collect([
            $record->teacher?->name,
            $record->topic?->subject?->name,
            $record->topic?->name,
            $record->academicLevel?->name,
        ])->filter()->implode(' · ');
    }

    public static function form(Schema $schema): Schema
    {
        return InstructorSubjectTopicForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InstructorSubjectTopicsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructorSubjectTopics::route('/'),
            'create' => CreateInstructorSubjectTopic::route('/create'),
            'edit' => EditInstructorSubjectTopic::route('/{record}/edit'),
        ];
    }
}
