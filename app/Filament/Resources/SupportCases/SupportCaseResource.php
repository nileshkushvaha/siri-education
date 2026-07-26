<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportCases;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\SupportCases\Pages\CreateSupportCase;
use App\Filament\Resources\SupportCases\Pages\ListSupportCases;
use App\Filament\Resources\SupportCases\Pages\ViewSupportCase;
use App\Filament\Resources\SupportCases\RelationManagers\RepliesRelationManager;
use App\Filament\Resources\SupportCases\Schemas\SupportCaseForm;
use App\Filament\Resources\SupportCases\Schemas\SupportCaseInfolist;
use App\Filament\Resources\SupportCases\Tables\SupportCasesTable;
use App\Models\SupportCase;
use App\SupportCases\Enums\SupportCasePriority;
use App\SupportCases\Enums\SupportCaseStatus;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * GAP-016 / SRS Chapter 25 staff surface. Only admin-operational cases
 * are created here (§25.16) — student/instructor self-service creation
 * lives on the frontend dashboard. Every lifecycle mutation delegates
 * to SupportCaseService; there is no Edit page and no delete action of
 * any kind (§Business Rules "no hard-delete").
 */
class SupportCaseResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = SupportCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|\UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $recordTitleAttribute = 'case_number';

    public static function form(Schema $schema): Schema
    {
        return SupportCaseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SupportCaseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupportCasesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RepliesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportCases::route('/'),
            'create' => CreateSupportCase::route('/create'),
            'view' => ViewSupportCase::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['creator', 'student', 'instructor', 'assignee']);
    }

    public static function getNavigationBadge(): ?string
    {
        $critical = SupportCase::query()
            ->where('priority', SupportCasePriority::Critical)
            ->whereNotIn('status', [SupportCaseStatus::Resolved, SupportCaseStatus::Closed])
            ->count();

        return $critical > 0 ? (string) $critical : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', SupportCase::class) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('createAdminCase', SupportCase::class) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
