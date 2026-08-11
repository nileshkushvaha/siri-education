<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Academic\Pages\CreateEducationSystem;
use App\Filament\Resources\Academic\Pages\EditEducationSystem;
use App\Filament\Resources\Academic\Pages\ListEducationSystems;
use App\Filament\Resources\Academic\RelationManagers\AcademicLevelMappingsRelationManager;
use App\Filament\Resources\Academic\RelationManagers\CountryMappingsRelationManager;
use App\Filament\Resources\Academic\RelationManagers\CurriculumMappingsRelationManager;
use App\Filament\Resources\Academic\Schemas\EducationSystemForm;
use App\Filament\Resources\Academic\Tables\EducationSystemsTable;
use App\Models\EducationSystem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EducationSystemResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = EducationSystem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Education Systems';

    protected static ?string $modelLabel = 'Education System';

    protected static ?string $pluralModelLabel = 'Education Systems';

    protected static string|\UnitEnum|null $navigationGroup = 'Academic';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 9;

    public static function form(Schema $schema): Schema
    {
        return EducationSystemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EducationSystemsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CountryMappingsRelationManager::class,
            AcademicLevelMappingsRelationManager::class,
            CurriculumMappingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEducationSystems::route('/'),
            'create' => CreateEducationSystem::route('/create'),
            'edit' => EditEducationSystem::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', EducationSystem::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', EducationSystem::class) ?? false;
    }
}
