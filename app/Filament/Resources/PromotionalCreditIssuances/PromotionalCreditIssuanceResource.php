<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalCreditIssuances;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\PromotionalCreditIssuances\Pages\ListPromotionalCreditIssuances;
use App\Filament\Resources\PromotionalCreditIssuances\Pages\ViewPromotionalCreditIssuance;
use App\Filament\Resources\PromotionalCreditIssuances\Schemas\PromotionalCreditIssuanceInfolist;
use App\Filament\Resources\PromotionalCreditIssuances\Tables\PromotionalCreditIssuancesTable;
use App\Models\PromotionalCreditIssuance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * GAP-041 issuance history + the single "issue a credit" entry point
 * (requirement #7). Issuances are created only through
 * PromotionalCreditService (via the header action on the List page) —
 * there is intentionally no Create/Edit page and no delete action of
 * any kind (immutable history).
 */
class PromotionalCreditIssuanceResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = PromotionalCreditIssuance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Promotional Credit Issuances';

    protected static string|\UnitEnum|null $navigationGroup = 'Referral';

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?int $navigationSort = 3;

    public static function infolist(Schema $schema): Schema
    {
        return PromotionalCreditIssuanceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromotionalCreditIssuancesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromotionalCreditIssuances::route('/'),
            'view' => ViewPromotionalCreditIssuance::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['student', 'campaign', 'issuedByUser']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', PromotionalCreditIssuance::class) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
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
