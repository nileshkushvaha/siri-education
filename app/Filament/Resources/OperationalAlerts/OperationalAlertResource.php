<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationalAlerts;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\OperationalAlerts\Pages\ListOperationalAlerts;
use App\Filament\Resources\OperationalAlerts\Pages\ViewOperationalAlert;
use App\Filament\Resources\OperationalAlerts\Schemas\OperationalAlertInfolist;
use App\Filament\Resources\OperationalAlerts\Tables\OperationalAlertsTable;
use App\Models\OperationalAlert;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only-by-design operational-alert queue. No
 * Create or Edit page exists; a row is only ever written by
 * OperationalAlertService. Acknowledge/resolve actions live on the
 * table and call that service exclusively.
 */
class OperationalAlertResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = OperationalAlert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Operational Alerts';

    protected static ?string $modelLabel = 'Operational Alert';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    public static function infolist(Schema $schema): Schema
    {
        return OperationalAlertInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OperationalAlertsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOperationalAlerts::route('/'),
            'view' => ViewOperationalAlert::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['acknowledgedByUser', 'resolvedByUser']);
    }

    public static function getNavigationBadge(): ?string
    {
        $open = OperationalAlert::query()->where('status', 'open')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', OperationalAlert::class) ?? false;
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
