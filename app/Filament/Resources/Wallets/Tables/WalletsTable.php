<?php

declare(strict_types=1);

namespace App\Filament\Resources\Wallets\Tables;

use App\Filament\Support\Tables\AdminListTable;
use App\Models\Wallet;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Support\WalletMoneyFormatter;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WalletsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('currency_code')
                    ->label('Currency')
                    ->badge(),
                TextColumn::make('balance_minor')
                    ->label('Balance')
                    ->state(fn (Wallet $record): string => WalletMoneyFormatter::format($record->balance_minor, $record->currency, $record->currency_code))
                    ->sortable(),
                TextColumn::make('available_balance_minor')
                    ->label('Available')
                    ->state(fn (Wallet $record): string => WalletMoneyFormatter::format($record->available_balance_minor, $record->currency, $record->currency_code))
                    ->toggleable(),
                TextColumn::make('held_balance_minor')
                    ->label('Held')
                    ->state(fn (Wallet $record): string => WalletMoneyFormatter::format($record->held_balance_minor, $record->currency, $record->currency_code))
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (WalletStatus $state): string => $state->label())
                    ->color(fn (WalletStatus $state): string => $state->color()),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('currency_code')
                    ->label('Currency')
                    ->relationship('currency', 'code'),
                SelectFilter::make('status')
                    ->options(collect(WalletStatus::cases())
                        ->mapWithKeys(fn (WalletStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');

        return AdminListTable::apply($table);
    }
}
