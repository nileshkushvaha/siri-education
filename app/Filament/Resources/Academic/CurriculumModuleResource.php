<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic;

use App\Filament\Resources\Academic\Pages\EditCurriculumModule;
use App\Filament\Resources\Academic\RelationManagers\CurriculumModuleTopicsRelationManager;
use App\Filament\Resources\Academic\Schemas\CurriculumModuleForm;
use App\Models\CurriculumModule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Deliberately not registered in the sidebar (no NavigationRegistry
 * entry, no index page) — reached only via the "Manage Topics" link
 * from CurriculumModulesRelationManager. Its sole purpose is hosting
 * the Topics relation manager for one module at a time.
 */
class CurriculumModuleResource extends Resource
{
    protected static ?string $model = CurriculumModule::class;

    protected static ?string $slug = 'academic/curriculum-modules';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $modelLabel = 'Curriculum Module';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return CurriculumModuleForm::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            CurriculumModuleTopicsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'edit' => EditCurriculumModule::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', CurriculumModule::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
