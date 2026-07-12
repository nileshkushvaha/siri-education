<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingPayments\Tables;

use App\Booking\Contracts\BookingPaymentReconciliationServiceInterface;
use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\PaymentProviderCode;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\GatewayRequestException;
use App\Models\BookingPayment;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingPaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking.reference')
                    ->label('Booking')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Student')
                    ->default('Guest')
                    ->searchable(),
                TextColumn::make('provider')
                    ->badge(),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->state(fn (BookingPayment $record): string => number_format($record->amount_minor / 100, 2).' '.$record->currency_code)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BookingPaymentRecordStatus $state): string => $state->label())
                    ->color(fn (BookingPaymentRecordStatus $state): string => $state->color()),
                TextColumn::make('payment_method')
                    ->label('Method')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('provider_order_id')
                    ->label('Order ID')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_synced_at')
                    ->label('Last Provider Sync')
                    ->dateTime()
                    ->placeholder('Never')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('failure_reason')
                    ->label('Safe Failure Reason')
                    ->state(fn (BookingPayment $record): ?string => $record->metadata['manual_resolution_reason']
                        ?? $record->metadata['refund_reason']
                        ?? null)
                    ->placeholder('—')
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('resolution')
                    ->label('Resolution')
                    ->badge()
                    ->state(fn (BookingPayment $record): ?string => $record->metadata['refund_resolution']
                        ?? ((bool) ($record->metadata['manual_resolution_required'] ?? false) ? 'manual_resolution_required' : null)
                        ?? ((bool) ($record->metadata['wallet_ledger_entry_id'] ?? false) ? 'wallet_credited' : null))
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'manual_resolution_required' => 'Needs manual resolution',
                        'wallet_credited' => 'Wallet credited',
                        'provider_refund_pending' => 'Provider refund pending',
                        'provider_refunded' => 'Refunded via provider',
                        'provider_refunded_externally' => 'Refunded via provider (webhook)',
                        default => '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'manual_resolution_required' => 'danger',
                        'wallet_credited' => 'info',
                        'provider_refund_pending' => 'warning',
                        'provider_refunded', 'provider_refunded_externally' => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(BookingPaymentRecordStatus::cases())
                        ->mapWithKeys(fn (BookingPaymentRecordStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('provider')
                    ->options(collect(PaymentProviderCode::cases())
                        ->mapWithKeys(fn (PaymentProviderCode $p) => [$p->value => $p->label()])
                        ->toArray()),
                SelectFilter::make('currency_code')
                    ->label('Currency')
                    ->options(fn (): array => BookingPayment::query()
                        ->distinct()
                        ->orderBy('currency_code')
                        ->pluck('currency_code', 'currency_code')
                        ->toArray()),
                Filter::make('manual_resolution_required')
                    ->label('Needs manual resolution')
                    ->query(fn (Builder $query): Builder => $query->whereRaw(
                        "JSON_EXTRACT(metadata, '$.manual_resolution_required') = true",
                    )),
                Filter::make('created_at')
                    ->label('Date')
                    ->schema([
                        Grid::make(2)->schema([
                            DatePicker::make('created_from')->label('From'),
                            DatePicker::make('created_until')->label('Until'),
                        ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('retry_verification')
                    ->label('Retry verification')
                    ->icon('heroicon-m-arrow-path')
                    ->authorize(fn (BookingPayment $record): bool => auth()->user()?->can('retryVerification', $record) ?? false)
                    ->visible(fn (BookingPayment $record): bool => ! $record->status->isTerminal() && $record->provider_order_id !== null)
                    ->action(function (BookingPayment $record): void {
                        try {
                            // Re-runs an authenticated provider status fetch
                            // through BookingPaymentReconciliationService —
                            // the exact same applyProviderStatus() path the
                            // scheduled sweep and webhook use. Never edits
                            // status directly, never marks the record paid.
                            app(BookingPaymentReconciliationServiceInterface::class)->reconcileAttempt($record);
                            Notification::make()->title('Verification re-checked')->success()->send();
                        } catch (GatewayRequestException|BookingException $e) {
                            Notification::make()->title('Verification failed')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('refund_via_provider')
                    ->label('Refund via Provider')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Exception path only — calls the real gateway directly. The normal cancellation flow already credits the wallet automatically; use this only for a duplicate charge, compliance requirement, or finance correction.')
                    ->authorize(fn (BookingPayment $record): bool => auth()->user()?->can('refundViaProvider', $record) ?? false)
                    ->visible(fn (BookingPayment $record): bool => $record->status === BookingPaymentRecordStatus::Captured
                        && ($record->metadata['refund_resolution'] ?? null) === null
                        && $record->booking?->payment_status?->value === 'paid')
                    ->form([
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(1000)
                            ->helperText('Mandatory — recorded on the audit trail.'),
                    ])
                    ->action(function (BookingPayment $record, array $data): void {
                        try {
                            app(BookingPaymentServiceInterface::class)->refundViaProvider($record->booking, auth()->user(), $data['reason']);
                            Notification::make()->title('Refunded via provider')->success()->send();
                        } catch (BookingException $e) {
                            Notification::make()->title('Refund failed')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }
}
