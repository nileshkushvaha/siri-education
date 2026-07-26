<?php

namespace App\Filament\Resources\Academic;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Academic\Pages\CreateSubjectTopic;
use App\Filament\Resources\Academic\Pages\EditSubjectTopic;
use App\Filament\Resources\Academic\Pages\ListSubjectTopics;
use App\Filament\Resources\Academic\Schemas\SubjectTopicForm;
use App\Filament\Resources\Academic\Tables\SubjectTopicsTable;
use App\Models\SubjectTopic;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SubjectTopicResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = SubjectTopic::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'Subject Topics';

    protected static ?string $modelLabel = 'Subject Topic';

    protected static ?string $pluralModelLabel = 'Subject Topics';

    protected static string|\UnitEnum|null $navigationGroup = 'Academic';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return SubjectTopicForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubjectTopicsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubjectTopics::route('/'),
            'create' => CreateSubjectTopic::route('/create'),
            'edit' => EditSubjectTopic::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
