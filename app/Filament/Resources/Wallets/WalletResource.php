<?php

declare(strict_types=1);

namespace App\Filament\Resources\Wallets;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Wallets\Pages\ListWallets;
use App\Filament\Resources\Wallets\Pages\ViewWallet;
use App\Filament\Resources\Wallets\RelationManagers\LedgerEntriesRelationManager;
use App\Filament\Resources\Wallets\Schemas\WalletInfolist;
use App\Filament\Resources\Wallets\Tables\WalletsTable;
use App\Models\Wallet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only by design: no Create or Edit page exists. A wallet is only
 * ever created through WalletService::getOrCreateWallet() and only ever
 * mutated through WalletService/WalletLedgerService — freeze/unfreeze/
 * close/admin-adjustment are header actions on ViewWallet, all
 * service-backed, never a raw form field.
 */
class WalletResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = Wallet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $navigationLabel = 'Wallets';

    protected static ?string $modelLabel = 'Wallet';

    protected static string|\UnitEnum|null $navigationGroup = 'Wallet';

    protected static ?int $navigationSort = 1;

    public static function infolist(Schema $schema): Schema
    {
        return WalletInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WalletsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LedgerEntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWallets::route('/'),
            'view' => ViewWallet::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'currency']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Wallet::class) ?? false;
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
