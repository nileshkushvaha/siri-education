<?php

declare(strict_types=1);

namespace App\Filament\Resources\Wallets\RelationManagers;

use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Wallet\Enums\WalletLedgerDirection;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletLedgerStatus;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Support\WalletMoneyFormatter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Read-only ledger view — no create/edit/delete. Reversal is the only
 * mutation, and it always creates a new offsetting row rather than
 * touching this one, so the table itself never needs a delete action.
 */
class LedgerEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static ?string $title = 'Ledger';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedBanknotes;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('direction')
                    ->badge()
                    ->formatStateUsing(fn (WalletLedgerDirection $state): string => $state->label())
                    ->color(fn (WalletLedgerDirection $state): string => $state === WalletLedgerDirection::Credit ? 'success' : 'danger'),
                TextColumn::make('entry_type')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (WalletLedgerEntryType $state): string => $state->label()),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->state(fn (WalletLedgerEntry $record): string => WalletMoneyFormatter::format($record->amount_minor, $record->currency, $record->currency_code)),
                TextColumn::make('balance_after_minor')
                    ->label('Balance after')
                    ->state(fn (WalletLedgerEntry $record): string => WalletMoneyFormatter::format($record->balance_after_minor, $record->currency, $record->currency_code)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (WalletLedgerStatus $state): string => $state->label())
                    ->color(fn (WalletLedgerStatus $state): string => $state->color()),
                TextColumn::make('description')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->options(collect(WalletLedgerDirection::cases())->mapWithKeys(fn ($d) => [$d->value => $d->label()])->toArray()),
                SelectFilter::make('status')
                    ->options(collect(WalletLedgerStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->toArray()),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('reverse')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('danger')
                    ->authorize(fn (): bool => auth()->user()?->can('manage', Wallet::class) ?? false)
                    ->visible(fn (WalletLedgerEntry $record): bool => $record->status === WalletLedgerStatus::Posted)
                    ->requiresConfirmation()
                    ->form([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(function (WalletLedgerEntry $record, array $data): void {
                        try {
                            app(WalletLedgerService::class)->reverse($record, auth()->user(), $data['reason']);
                            Notification::make()->title('Entry reversed')->success()->send();
                        } catch (WalletException $e) {
                            Notification::make()->title('Reversal failed')->body($e->getMessage())->danger()->send();
                        } catch (AuthorizationException) {
                            Notification::make()->title('You are not authorized to reverse this entry.')->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }
}
