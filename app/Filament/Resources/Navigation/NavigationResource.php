<?php

declare(strict_types=1);

namespace App\Filament\Resources\Navigation;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Navigation\Pages\CreateNavigation;
use App\Filament\Resources\Navigation\Pages\EditNavigation;
use App\Filament\Resources\Navigation\Pages\ListNavigations;
use App\Filament\Resources\Navigation\Schemas\NavigationMenuForm;
use App\Filament\Resources\Navigation\Tables\NavigationMenusTable;
use App\Models\NavigationMenu;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class NavigationResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = NavigationMenu::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static ?string $navigationLabel = 'Navigation';

    protected static ?string $modelLabel = 'Navigation Menu';

    protected static ?string $pluralModelLabel = 'Navigation Menus';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return NavigationMenuForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NavigationMenusTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNavigations::route('/'),
            'create' => CreateNavigation::route('/create'),
            'edit' => EditNavigation::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', NavigationMenu::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', NavigationMenu::class) ?? false;
    }
}
