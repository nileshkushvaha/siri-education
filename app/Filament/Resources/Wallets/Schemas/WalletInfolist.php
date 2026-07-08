<?php

declare(strict_types=1);

namespace App\Filament\Resources\Wallets\Schemas;

use App\Models\Wallet;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Support\WalletMoneyFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WalletInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Wallet')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('user.name')
                            ->label('User'),
                        TextEntry::make('currency_code')
                            ->label('Currency')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (WalletStatus $state): string => $state->label())
                            ->color(fn (WalletStatus $state): string => $state->color()),
                    ]),
                ]),

            Section::make('Balances')
                ->description('Read-only — every change is a WalletLedgerEntry, never a direct edit.')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('balance_minor')
                            ->label('Total balance')
                            ->state(fn (Wallet $record): string => WalletMoneyFormatter::format($record->balance_minor, $record->currency, $record->currency_code)),
                        TextEntry::make('available_balance_minor')
                            ->label('Available')
                            ->state(fn (Wallet $record): string => WalletMoneyFormatter::format($record->available_balance_minor, $record->currency, $record->currency_code)),
                        TextEntry::make('held_balance_minor')
                            ->label('Held')
                            ->state(fn (Wallet $record): string => WalletMoneyFormatter::format($record->held_balance_minor, $record->currency, $record->currency_code)),
                    ]),
                ]),
        ]);
    }
}
