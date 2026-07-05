<?php

namespace App\Filament\Resources\Academic;

use App\Filament\Resources\Academic\Pages\CreateSkillLevel;
use App\Filament\Resources\Academic\Pages\EditSkillLevel;
use App\Filament\Resources\Academic\Pages\ListSkillLevels;
use App\Filament\Resources\Academic\Schemas\SkillLevelForm;
use App\Filament\Resources\Academic\Tables\SkillLevelsTable;
use App\Models\SkillLevel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SkillLevelResource extends Resource
{
    protected static ?string $model = SkillLevel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Skill Levels';

    protected static ?string $modelLabel = 'Skill Level';

    protected static ?string $pluralModelLabel = 'Skill Levels';

    protected static string|\UnitEnum|null $navigationGroup = 'Academic';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return SkillLevelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SkillLevelsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSkillLevels::route('/'),
            'create' => CreateSkillLevel::route('/create'),
            'edit' => EditSkillLevel::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', SkillLevel::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', SkillLevel::class) ?? false;
    }
}
