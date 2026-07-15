<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingTypes\Tables;

use App\Filament\Support\CsvExport;
use App\Models\BookingType;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class BookingTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('duration_minutes')
                    ->label('Duration')
                    ->suffix(' min')
                    ->sortable(),
                TextColumn::make('buffer_minutes')
                    ->label('Buffer')
                    ->suffix(' min')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_paid')
                    ->boolean()
                    ->label('Paid'),
                IconColumn::make('requires_approval')
                    ->boolean()
                    ->label('Approval'),
                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                TextColumn::make('bookings_count')
                    ->counts('bookings')
                    ->label('Bookings')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('is_paid')->label('Paid'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->authorize(fn (): bool => auth()->user()?->can('create', BookingType::class) ?? false)
                        ->action(function (Collection $records): void {
                            $records->each->update(['is_active' => true]);
                            Notification::make()->title('Booking types activated')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->icon('heroicon-m-no-symbol')
                        ->color('warning')
                        ->authorize(fn (): bool => auth()->user()?->can('create', BookingType::class) ?? false)
                        ->action(function (Collection $records): void {
                            $records->each->update(['is_active' => false]);
                            Notification::make()->title('Booking types deactivated')->warning()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('export')
                        ->label('Export CSV')
                        ->icon('heroicon-m-arrow-down-tray')
                        ->action(fn (Collection $records) => CsvExport::download($records, [
                            'Key' => 'key',
                            'Name' => 'name',
                            'Duration (min)' => 'duration_minutes',
                            'Buffer (min)' => 'buffer_minutes',
                            'Paid' => fn (BookingType $t): string => $t->is_paid ? 'Yes' : 'No',
                            'Requires approval' => fn (BookingType $t): string => $t->requires_approval ? 'Yes' : 'No',
                            'Active' => fn (BookingType $t): string => $t->is_active ? 'Yes' : 'No',
                        ], 'booking-types.csv'))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order');
    }
}
