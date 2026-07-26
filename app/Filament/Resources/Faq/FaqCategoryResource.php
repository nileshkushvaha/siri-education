<?php

namespace App\Filament\Resources\Faq;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Faq\Pages\CreateFaqCategory;
use App\Filament\Resources\Faq\Pages\EditFaqCategory;
use App\Filament\Resources\Faq\Pages\ListFaqCategories;
use App\Filament\Resources\Faq\Schemas\FaqCategoryForm;
use App\Filament\Resources\Faq\Tables\FaqCategoriesTable;
use App\Models\FaqCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FaqCategoryResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = FaqCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static ?string $navigationLabel = 'FAQ Categories';

    protected static ?string $modelLabel = 'FAQ Category';

    protected static ?string $pluralModelLabel = 'FAQ Categories';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return FaqCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FaqCategoriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFaqCategories::route('/'),
            'create' => CreateFaqCategory::route('/create'),
            'edit' => EditFaqCategory::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', FaqCategory::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', FaqCategory::class) ?? false;
    }
}
