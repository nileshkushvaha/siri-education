<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingPayments\Tables;

use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Models\BookingPayment;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(BookingPaymentRecordStatus::cases())
                        ->mapWithKeys(fn (BookingPaymentRecordStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('provider')
                    ->options(['razorpay' => 'Razorpay', 'fake' => 'Fake (dev)']),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }
}
