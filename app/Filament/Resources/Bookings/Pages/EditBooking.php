<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditBooking extends EditRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (Booking $record): bool => $record->status->isTerminal())
                ->before($this->guardAgainstNonTerminalDelete()),
            RestoreAction::make(),
            ForceDeleteAction::make()
                ->visible(fn (Booking $record): bool => $record->status->isTerminal())
                ->before($this->guardAgainstNonTerminalDelete()),
        ];
    }

    /**
     * Soft-deleting (or force-deleting) a still-active booking removes it
     * from every availability conflict check (SoftDeletingScope hides it
     * from the `active()` scope) without ever going through cancellation —
     * no event, no notification, no reason recorded, and the slot is
     * silently freed. Deletes are only safe once the booking has reached a
     * terminal status through the normal lifecycle.
     */
    private function guardAgainstNonTerminalDelete(): \Closure
    {
        return function (Action $action): void {
            /** @var Booking $record */
            $record = $this->getRecord();

            if ($record->status->isTerminal()) {
                return;
            }

            Notification::make()
                ->title('Cannot delete this booking')
                ->body('Cancel or complete this booking first — deleting an active booking would silently free its slot without going through cancellation.')
                ->danger()
                ->send();

            $action->halt();
        };
    }
}
