<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Academic\Pages\EditCurriculumVersion;
use App\Filament\Resources\Academic\Pages\ListCurriculumVersions;
use App\Filament\Resources\Academic\RelationManagers\CurriculumModulesRelationManager;
use App\Filament\Resources\Academic\Schemas\CurriculumVersionForm;
use App\Filament\Resources\Academic\Tables\CurriculumVersionsTable;
use App\Models\CurriculumVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

/**
 * Versions are never created directly from this resource (no 'create'
 * page) — they only come into existence through
 * CurriculumService::createCurriculum()/createNewVersion(), reached
 * from CurriculumResource's Versions relation manager. This resource
 * exists for browsing/filtering/inspecting versions and driving their
 * lifecycle (publish/archive/retire) plus module management.
 */
class CurriculumVersionResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = CurriculumVersion::class;

    protected static ?string $slug = 'academic/curriculum-versions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Curriculum Versions';

    protected static ?string $modelLabel = 'Curriculum Version';

    protected static ?string $pluralModelLabel = 'Curriculum Versions';

    protected static string|\UnitEnum|null $navigationGroup = 'Academic';

    protected static bool $shouldRegisterNavigation = false;

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record instanceof CurriculumVersion) {
            return null;
        }

        $record->loadMissing('curriculum');

        return trim(($record->curriculum?->name ?? 'Curriculum').' v'.$record->version_number);
    }

    public static function form(Schema $schema): Schema
    {
        return CurriculumVersionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CurriculumVersionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CurriculumModulesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurriculumVersions::route('/'),
            'edit' => EditCurriculumVersion::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', CurriculumVersion::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
