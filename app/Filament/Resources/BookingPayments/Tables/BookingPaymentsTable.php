<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingPayments\Tables;

use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\PaymentProviderCode;
use App\Models\BookingPayment;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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
                TextColumn::make('resolution')
                    ->label('Resolution')
                    ->badge()
                    ->state(fn (BookingPayment $record): ?string => match (true) {
                        (bool) ($record->metadata['manual_resolution_required'] ?? false) => 'manual',
                        (bool) ($record->metadata['wallet_ledger_entry_id'] ?? false) => 'wallet_credited',
                        default => null,
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'manual' => 'Needs manual resolution',
                        'wallet_credited' => 'Wallet credited',
                        default => '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'manual' => 'danger',
                        'wallet_credited' => 'info',
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
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }
}
