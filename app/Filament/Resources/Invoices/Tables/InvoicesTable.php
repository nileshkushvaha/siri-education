<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Tables;

use App\Models\Invoice;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Invoice #')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('user.name')
                    ->label('Student')
                    ->searchable(),
                TextColumn::make('service_description')
                    ->label('For')
                    ->limit(50),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->state(fn (Invoice $record): string => number_format($record->amount_minor / 100, 2).' '.$record->currency_code)
                    ->sortable(),
                TextColumn::make('payment_reference')
                    ->label('Payment Ref')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('issued_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('currency_code')
                    ->label('Currency')
                    ->options(fn (): array => Invoice::query()
                        ->distinct()
                        ->orderBy('currency_code')
                        ->pluck('currency_code', 'currency_code')
                        ->toArray()),
                Filter::make('issued_at')
                    ->label('Date')
                    ->schema([
                        Grid::make(2)->schema([
                            DatePicker::make('issued_from')->label('From'),
                            DatePicker::make('issued_until')->label('Until'),
                        ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['issued_from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('issued_at', '>=', $date))
                        ->when($data['issued_until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('issued_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('issued_at', 'desc');
    }
}
