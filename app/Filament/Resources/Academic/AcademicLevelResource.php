<?php

namespace App\Filament\Resources\Academic;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Academic\Pages\CreateAcademicLevel;
use App\Filament\Resources\Academic\Pages\EditAcademicLevel;
use App\Filament\Resources\Academic\Pages\ListAcademicLevels;
use App\Filament\Resources\Academic\Schemas\AcademicLevelForm;
use App\Filament\Resources\Academic\Tables\AcademicLevelsTable;
use App\Models\AcademicLevel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AcademicLevelResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = AcademicLevel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Academic Levels';

    protected static ?string $modelLabel = 'Academic Level';

    protected static ?string $pluralModelLabel = 'Academic Levels';

    protected static string|\UnitEnum|null $navigationGroup = 'Academic';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return AcademicLevelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AcademicLevelsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAcademicLevels::route('/'),
            'create' => CreateAcademicLevel::route('/create'),
            'edit' => EditAcademicLevel::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', AcademicLevel::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', AcademicLevel::class) ?? false;
    }
}
