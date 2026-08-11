<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Academic\Pages\CreateCurriculum;
use App\Filament\Resources\Academic\Pages\EditCurriculum;
use App\Filament\Resources\Academic\Pages\ListCurricula;
use App\Filament\Resources\Academic\RelationManagers\CurriculumVersionsRelationManager;
use App\Filament\Resources\Academic\Schemas\CurriculumForm;
use App\Filament\Resources\Academic\Tables\CurriculaTable;
use App\Models\Curriculum;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CurriculumResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = Curriculum::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Curricula';

    protected static ?string $modelLabel = 'Curriculum';

    protected static ?string $pluralModelLabel = 'Curricula';

    protected static string|\UnitEnum|null $navigationGroup = 'Academic';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CurriculumForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CurriculaTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CurriculumVersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurricula::route('/'),
            'create' => CreateCurriculum::route('/create'),
            'edit' => EditCurriculum::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Curriculum::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', Curriculum::class) ?? false;
    }
}
