<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorSettlementBatches\Tables;

use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Enums\SettlementBatchStatus;
use App\Earnings\Exceptions\EarningException;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\InstructorSettlementBatch;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Batch lifecycle mutations route through InstructorEarningService —
 * approve, mark paid (manual money-moved confirmation, no external
 * transfer), and cancel (draft/failed only, earnings return to pool).
 */
class InstructorSettlementBatchesTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('batch_reference')
                    ->label('Reference')
                    ->searchable()
                    ->fontFamily('mono')
                    ->copyable(),
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('currency_code')
                    ->label('Currency'),
                TextColumn::make('total_amount_minor')
                    ->label('Total')
                    ->state(fn (InstructorSettlementBatch $record): string => MoneyFormatter::format($record->total_amount_minor, $record->currency_code))
                    ->sortable(),
                TextColumn::make('earnings_count')
                    ->label('Earnings')
                    ->counts('earnings'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SettlementBatchStatus $state): string => $state->label())
                    ->color(fn (SettlementBatchStatus $state): string => $state->color()),
                TextColumn::make('paid_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('payment_reference')
                    ->label('Payment Ref')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(SettlementBatchStatus::cases())
                        ->mapWithKeys(fn (SettlementBatchStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('instructor_id')
                    ->label('Instructor')
                    ->relationship('instructor', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->icon('heroicon-m-check-badge')
                    ->color('info')
                    ->requiresConfirmation()
                    ->authorize(fn (InstructorSettlementBatch $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->visible(fn (InstructorSettlementBatch $record): bool => $record->status === SettlementBatchStatus::Draft)
                    ->action(fn (InstructorSettlementBatch $record) => self::callService(
                        fn (InstructorEarningServiceInterface $service) => $service->approveSettlementBatch($record, auth()->user()),
                        'Batch approved',
                    )),

                Action::make('mark_paid')
                    ->label('Mark Paid')
                    ->icon('heroicon-m-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Confirms money was moved manually outside the system. No transfer is executed.')
                    ->authorize(fn (InstructorSettlementBatch $record): bool => auth()->user()?->can('markPaid', $record) ?? false)
                    ->visible(fn (InstructorSettlementBatch $record): bool => $record->status->canTransitionTo(SettlementBatchStatus::Paid))
                    ->form([
                        TextInput::make('payment_reference')
                            ->label('Payment reference (bank/UPI ref, optional)')
                            ->maxLength(255),
                    ])
                    ->action(fn (InstructorSettlementBatch $record, array $data) => self::callService(
                        fn (InstructorEarningServiceInterface $service) => $service->markSettlementBatchPaid($record, auth()->user(), $data['payment_reference'] ?? null),
                        'Batch marked paid — earnings settled',
                    )),

                Action::make('cancel')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn (InstructorSettlementBatch $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->visible(fn (InstructorSettlementBatch $record): bool => in_array($record->status, [SettlementBatchStatus::Draft, SettlementBatchStatus::Failed], true))
                    ->form([
                        Textarea::make('reason')->maxLength(1000),
                    ])
                    ->action(fn (InstructorSettlementBatch $record, array $data) => self::callService(
                        fn (InstructorEarningServiceInterface $service) => $service->cancelSettlementBatch($record, auth()->user(), $data['reason'] ?? null),
                        'Batch cancelled — earnings returned to pool',
                    )),
            ])
            ->defaultSort('created_at', 'desc');

        return AdminListTable::apply($table);
    }

    private static function callService(callable $callback, string $successTitle): void
    {
        try {
            $callback(app(InstructorEarningServiceInterface::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (EarningException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
