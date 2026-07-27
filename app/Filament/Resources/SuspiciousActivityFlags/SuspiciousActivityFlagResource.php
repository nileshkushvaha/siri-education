<?php

declare(strict_types=1);

namespace App\Filament\Resources\SuspiciousActivityFlags;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\SuspiciousActivityFlags\Pages\ListSuspiciousActivityFlags;
use App\Filament\Resources\SuspiciousActivityFlags\Pages\ViewSuspiciousActivityFlag;
use App\Filament\Resources\SuspiciousActivityFlags\Schemas\SuspiciousActivityFlagInfolist;
use App\Filament\Resources\SuspiciousActivityFlags\Tables\SuspiciousActivityFlagsTable;
use App\Models\SuspiciousActivityFlag;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only-by-design compliance-flag
 * queue. No Create or Edit page exists; a row is only ever written by
 * ComplianceMonitoringService. Review actions (begin-review, resolve,
 * dismiss) live on the table and call that service exclusively — this
 * resource never mutates a flag directly.
 */
class SuspiciousActivityFlagResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = SuspiciousActivityFlag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $navigationLabel = 'Compliance Flags';

    protected static ?string $modelLabel = 'Suspicious Activity Flag';

    protected static string|\UnitEnum|null $navigationGroup = 'Compliance';

    public static function infolist(Schema $schema): Schema
    {
        return SuspiciousActivityFlagInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuspiciousActivityFlagsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuspiciousActivityFlags::route('/'),
            'view' => ViewSuspiciousActivityFlag::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['subject', 'actor', 'reviewer']);
    }

    public static function getNavigationBadge(): ?string
    {
        $open = SuspiciousActivityFlag::query()->where('status', 'open')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', SuspiciousActivityFlag::class) ?? false;
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
