<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorDocumentRequirements;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\InstructorDocumentRequirements\Pages\CreateInstructorDocumentRequirement;
use App\Filament\Resources\InstructorDocumentRequirements\Pages\EditInstructorDocumentRequirement;
use App\Filament\Resources\InstructorDocumentRequirements\Pages\ListInstructorDocumentRequirements;
use App\Filament\Resources\InstructorDocumentRequirements\Schemas\InstructorDocumentRequirementForm;
use App\Filament\Resources\InstructorDocumentRequirements\Tables\InstructorDocumentRequirementsTable;
use App\Models\InstructorDocumentRequirement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InstructorDocumentRequirementResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = InstructorDocumentRequirement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $navigationLabel = 'Document Requirements';

    protected static ?string $modelLabel = 'Document Requirement';

    protected static ?string $pluralModelLabel = 'Document Requirements';

    protected static string|\UnitEnum|null $navigationGroup = 'Instructor';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return InstructorDocumentRequirementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InstructorDocumentRequirementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructorDocumentRequirements::route('/'),
            'create' => CreateInstructorDocumentRequirement::route('/create'),
            'edit' => EditInstructorDocumentRequirement::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', InstructorDocumentRequirement::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', InstructorDocumentRequirement::class) ?? false;
    }

    /** No delete anywhere in this resource — see InstructorDocumentRequirement::PreventsHardDeletion. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
