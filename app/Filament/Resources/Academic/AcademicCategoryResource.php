<?php

namespace App\Filament\Resources\Academic;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Academic\Pages\CreateAcademicCategory;
use App\Filament\Resources\Academic\Pages\EditAcademicCategory;
use App\Filament\Resources\Academic\Pages\ListAcademicCategories;
use App\Filament\Resources\Academic\Schemas\AcademicCategoryForm;
use App\Filament\Resources\Academic\Tables\AcademicCategoriesTable;
use App\Models\AcademicCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AcademicCategoryResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = AcademicCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static ?string $navigationLabel = 'Academic Categories';

    protected static ?string $modelLabel = 'Academic Category';

    protected static ?string $pluralModelLabel = 'Academic Categories';

    protected static string|\UnitEnum|null $navigationGroup = 'Academic';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return AcademicCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AcademicCategoriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAcademicCategories::route('/'),
            'create' => CreateAcademicCategory::route('/create'),
            'edit' => EditAcademicCategory::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', AcademicCategory::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', AcademicCategory::class) ?? false;
    }
}
