<?php

declare(strict_types=1);

namespace App\Filament\Resources\Wallets\Pages;

use App\Filament\Resources\Wallets\WalletResource;
use App\Models\Wallet;
use App\Wallet\Enums\WalletLedgerDirection;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;

class ViewWallet extends ViewRecord
{
    protected static string $resource = WalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('freeze')
                ->icon('heroicon-m-lock-closed')
                ->color('warning')
                ->authorize(fn (Wallet $record): bool => auth()->user()?->can('manage', $record) ?? false)
                ->visible(fn (Wallet $record): bool => $record->status === WalletStatus::Active)
                ->form([Textarea::make('reason')->required()->maxLength(500)])
                ->action(function (Wallet $record, array $data): void {
                    $this->callWalletService(
                        fn () => app(WalletService::class)->freeze($record, auth()->user(), $data['reason']),
                        'Wallet frozen',
                    );
                }),

            Action::make('unfreeze')
                ->icon('heroicon-m-lock-open')
                ->color('success')
                ->authorize(fn (Wallet $record): bool => auth()->user()?->can('manage', $record) ?? false)
                ->visible(fn (Wallet $record): bool => $record->status === WalletStatus::Frozen)
                ->form([Textarea::make('reason')->required()->maxLength(500)])
                ->action(function (Wallet $record, array $data): void {
                    $this->callWalletService(
                        fn () => app(WalletService::class)->unfreeze($record, auth()->user(), $data['reason']),
                        'Wallet unfrozen',
                    );
                }),

            Action::make('close')
                ->icon('heroicon-m-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->authorize(fn (Wallet $record): bool => auth()->user()?->can('manage', $record) ?? false)
                ->visible(fn (Wallet $record): bool => $record->status !== WalletStatus::Closed)
                ->form([Textarea::make('reason')->required()->maxLength(500)])
                ->action(function (Wallet $record, array $data): void {
                    $this->callWalletService(
                        fn () => app(WalletService::class)->close($record, auth()->user(), $data['reason']),
                        'Wallet closed',
                    );
                }),

            Action::make('adjustment')
                ->label('Admin adjustment')
                ->icon('heroicon-m-banknotes')
                ->color('gray')
                ->authorize(fn (Wallet $record): bool => auth()->user()?->can('manage', $record) ?? false)
                ->visible(fn (Wallet $record): bool => $record->status !== WalletStatus::Closed)
                ->form([
                    Radio::make('direction')
                        ->options(['credit' => 'Credit (add funds)', 'debit' => 'Debit (remove funds)'])
                        ->default('credit')
                        ->required(),
                    TextInput::make('amount_minor')
                        ->label('Amount (smallest currency unit)')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->helperText(fn (Wallet $record): string => sprintf(
                            'Enter the amount in the smallest unit of this wallet\'s currency (%s) — e.g. 10000 equals 100.00 in its main unit.',
                            $record->currency_code,
                        )),
                    Textarea::make('reason')->required()->maxLength(500),
                ])
                ->action(function (Wallet $record, array $data): void {
                    $this->callWalletService(
                        fn () => app(WalletLedgerService::class)->adjustment(
                            $record,
                            (int) $data['amount_minor'],
                            WalletLedgerDirection::from($data['direction']),
                            auth()->user(),
                            $data['reason'],
                        ),
                        'Adjustment posted',
                    );
                }),
        ];
    }

    /** Run a service call, converting domain failures into panel notifications. */
    private function callWalletService(callable $callback, string $successTitle): void
    {
        try {
            $callback();
            Notification::make()->title($successTitle)->success()->send();
        } catch (WalletException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        } catch (AuthorizationException) {
            Notification::make()->title('You are not authorized to manage this wallet.')->danger()->send();
        }
    }
}
