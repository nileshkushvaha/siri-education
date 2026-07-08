<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Tables;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingException;
use App\Filament\Support\CsvExport;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every lifecycle mutation routes through BookingServiceInterface so
 * transition guards, locking, timeline, events, and notifications run
 * exactly as they do everywhere else — zero duplicated business logic.
 */
class BookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->copyable(),
                TextColumn::make('type.name')
                    ->label('Type')
                    ->sortable(),
                TextColumn::make('attendee_display')
                    ->label('Attendee')
                    ->state(fn (Booking $record): string => $record->attendeeName() ?? '—')
                    ->description(fn (Booking $record): ?string => $record->isGuest() ? 'Guest' : null),
                TextColumn::make('host.name')
                    ->label('Teacher')
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BookingStatus $state): string => $state->label())
                    ->color(fn (BookingStatus $state): string => $state->color()),
                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn (BookingPaymentStatus $state): string => $state->label())
                    ->color(fn (BookingPaymentStatus $state): string => $state->color())
                    ->toggleable(),
                TextColumn::make('price')
                    ->label('Amount')
                    ->money(fn (Booking $record): string => $record->currency ?? 'USD')
                    ->placeholder('Free')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(BookingStatus::cases())
                        ->mapWithKeys(fn (BookingStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('payment_status')
                    ->options(collect(BookingPaymentStatus::cases())
                        ->mapWithKeys(fn (BookingPaymentStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('booking_type_id')
                    ->label('Type')
                    ->relationship('type', 'name'),
                SelectFilter::make('host_id')
                    ->label('Teacher')
                    ->relationship('host', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('starts_between')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $q, $date) => $q->whereDate('starts_at', '>=', $date))
                        ->when($data['until'], fn (Builder $q, $date) => $q->whereDate('starts_at', '<=', $date))),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('confirm')
                    ->icon('heroicon-m-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(fn (Booking $record): bool => auth()->user()?->can('confirm', $record) ?? false)
                    ->visible(fn (Booking $record): bool => $record->status->canTransitionTo(BookingStatus::Confirmed))
                    ->action(fn (Booking $record) => self::callService(
                        fn (BookingServiceInterface $service) => $service->confirm($record),
                        'Booking confirmed',
                    )),

                Action::make('cancel')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->authorize(fn (Booking $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->visible(fn (Booking $record): bool => $record->status->canTransitionTo(BookingStatus::Cancelled))
                    ->form([
                        Textarea::make('reason')->maxLength(500),
                    ])
                    ->action(fn (Booking $record, array $data) => self::callService(
                        fn (BookingServiceInterface $service) => $service->cancel(
                            $record,
                            new CancelBookingData(BookingActor::Admin, $data['reason'] ?? null),
                        ),
                        'Booking cancelled',
                    )),

                Action::make('reschedule')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->authorize(fn (Booking $record): bool => auth()->user()?->can('reschedule', $record) ?? false)
                    ->visible(fn (Booking $record): bool => ! $record->status->isTerminal())
                    ->form([
                        DateTimePicker::make('starts_at')
                            ->label('New start (UTC)')
                            ->required()
                            ->seconds(false),
                        Textarea::make('reason')->maxLength(500),
                    ])
                    ->action(fn (Booking $record, array $data) => self::callService(
                        fn (BookingServiceInterface $service) => $service->reschedule(
                            $record,
                            new RescheduleBookingData(
                                CarbonImmutable::parse($data['starts_at'], 'UTC'),
                                BookingActor::Admin,
                                reason: $data['reason'] ?? null,
                            ),
                        ),
                        'Booking rescheduled',
                    )),

                Action::make('complete')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(fn (Booking $record): bool => auth()->user()?->can('complete', $record) ?? false)
                    ->visible(fn (Booking $record): bool => $record->status->canTransitionTo(BookingStatus::Completed))
                    ->action(fn (Booking $record) => self::callService(
                        fn (BookingServiceInterface $service) => $service->complete($record),
                        'Booking completed',
                    )),

                Action::make('no_show')
                    ->label('No show')
                    ->icon('heroicon-m-eye-slash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->authorize(fn (Booking $record): bool => auth()->user()?->can('complete', $record) ?? false)
                    ->visible(fn (Booking $record): bool => $record->status->canTransitionTo(BookingStatus::NoShow))
                    ->action(fn (Booking $record) => self::callService(
                        fn (BookingServiceInterface $service) => $service->markNoShow($record),
                        'Booking marked as no-show',
                    )),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('cancel_selected')
                        ->label('Cancel selected')
                        ->icon('heroicon-m-x-circle')
                        ->color('danger')
                        ->form([
                            Textarea::make('reason')->maxLength(500),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $service = app(BookingServiceInterface::class);
                            $cancelled = 0;
                            $skipped = 0;

                            foreach ($records as $booking) {
                                if (! (auth()->user()?->can('cancel', $booking) ?? false)) {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    $service->cancel($booking, new CancelBookingData(BookingActor::Admin, $data['reason'] ?? null));
                                    $cancelled++;
                                } catch (BookingException) {
                                    $skipped++;
                                }
                            }

                            Notification::make()
                                ->title(sprintf('%d cancelled, %d skipped', $cancelled, $skipped))
                                ->{$cancelled > 0 ? 'success' : 'warning'}()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('export')
                        ->label('Export CSV')
                        ->icon('heroicon-m-arrow-down-tray')
                        ->action(fn (Collection $records) => CsvExport::download($records->load(['type', 'host']), [
                            'Reference' => 'reference',
                            'Type' => 'type.name',
                            'Attendee' => fn (Booking $b): string => $b->attendeeName() ?? '',
                            'Attendee email' => fn (Booking $b): string => $b->attendee?->email ?? $b->guest_email ?? '',
                            'Teacher' => 'host.name',
                            'Starts (UTC)' => fn (Booking $b): string => $b->starts_at->toDateTimeString(),
                            'Ends (UTC)' => fn (Booking $b): string => $b->ends_at->toDateTimeString(),
                            'Timezone' => 'timezone',
                            'Status' => fn (Booking $b): string => $b->status->label(),
                            'Payment' => fn (Booking $b): string => $b->payment_status->label(),
                            'Price' => 'price',
                            'Currency' => 'currency',
                        ], 'bookings.csv'))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()
                        ->action(fn (Collection $records) => self::deleteTerminalOnly($records, force: false))
                        ->deselectRecordsAfterCompletion(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make()
                        ->action(fn (Collection $records) => self::deleteTerminalOnly($records, force: true))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('starts_at', 'desc');
    }

    /**
     * Deleting (or force-deleting) a still-active booking removes it from
     * every availability conflict check without going through cancellation —
     * see EditBooking::guardAgainstNonTerminalDelete() for the same rule on
     * the row-level actions. Bulk variants skip non-terminal records instead
     * of failing the whole batch.
     */
    private static function deleteTerminalOnly(Collection $records, bool $force): void
    {
        $deleted = 0;
        $skipped = 0;

        foreach ($records as $booking) {
            if (! $booking->status->isTerminal()) {
                $skipped++;

                continue;
            }

            $force ? $booking->forceDelete() : $booking->delete();
            $deleted++;
        }

        Notification::make()
            ->title(sprintf('%d %sdeleted, %d skipped (not terminal)', $deleted, $force ? 'permanently ' : '', $skipped))
            ->{$deleted > 0 ? 'success' : 'warning'}()
            ->send();
    }

    /** Run a service call, converting domain failures into panel notifications. */
    private static function callService(callable $callback, string $successTitle): void
    {
        try {
            $callback(app(BookingServiceInterface::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (BookingException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
