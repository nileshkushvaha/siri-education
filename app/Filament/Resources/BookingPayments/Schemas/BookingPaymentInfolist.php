<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingPayments\Schemas;

use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Models\BookingPayment;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BookingPaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payment attempt')
                ->description('Read-only — written only by the gateway integration, never edited here.')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('booking.reference')
                            ->label('Booking'),
                        TextEntry::make('user.name')
                            ->label('Student')
                            ->placeholder('Guest'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (BookingPaymentRecordStatus $state): string => $state->label())
                            ->color(fn (BookingPaymentRecordStatus $state): string => $state->color()),
                    ]),
                    Grid::make(3)->schema([
                        TextEntry::make('provider')
                            ->badge(),
                        TextEntry::make('amount_minor')
                            ->label('Amount')
                            ->state(fn (BookingPayment $record): string => number_format($record->amount_minor / 100, 2).' '.$record->currency_code),
                        TextEntry::make('payment_method')
                            ->label('Method')
                            ->placeholder('—'),
                    ]),
                ]),

            Section::make('Gateway references')
                ->description('Never the card/UPI details or the raw signature — only the order/payment identifiers needed for support.')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('provider_order_id')
                            ->label('Order ID')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('provider_payment_id')
                            ->label('Payment ID')
                            ->placeholder('—')
                            ->copyable(),
                    ]),
                    TextEntry::make('idempotency_key')
                        ->label('Idempotency key (booking payment reference)')
                        ->placeholder('—')
                        ->copyable(),
                ]),

            Section::make('Timeline')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('paid_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('failed_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->dateTime(),
                    ]),
                ]),
        ]);
    }
}
