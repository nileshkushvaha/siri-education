<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\Invoice;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Invoice')
                ->description('Every field below is a snapshot taken at issuance — read-only, never re-derived from current account/settings state.')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('invoice_number')
                            ->label('Invoice #')
                            ->copyable(),
                        TextEntry::make('student_name')
                            ->label('Student'),
                        TextEntry::make('billing_country')
                            ->placeholder('—'),
                    ]),
                    Grid::make(3)->schema([
                        TextEntry::make('amount_minor')
                            ->label('Amount')
                            ->state(fn (Invoice $record): string => number_format($record->amount_minor / 100, 2).' '.$record->currency_code),
                        TextEntry::make('payment_date')
                            ->dateTime(),
                        TextEntry::make('payment_reference')
                            ->label('Payment Reference')
                            ->copyable(),
                    ]),
                    TextEntry::make('service_description')
                        ->label('Service'),
                    Grid::make(2)->schema([
                        TextEntry::make('booking_reference')
                            ->placeholder('—'),
                        TextEntry::make('wallet_recharge_reference')
                            ->label('Wallet Recharge Reference')
                            ->placeholder('—'),
                    ]),
                ]),

            Section::make('Platform details')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('organization_name')
                            ->placeholder('—'),
                        TextEntry::make('organization_support_email')
                            ->label('Support Email')
                            ->placeholder('—'),
                    ]),
                ]),

            Section::make('Timeline')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('issued_at')
                            ->dateTime(),
                        TextEntry::make('created_at')
                            ->dateTime(),
                    ]),
                ]),
        ]);
    }
}
