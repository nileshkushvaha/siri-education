<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Pages;

use App\Booking\Contracts\BookingArchivalServiceInterface;
use App\Booking\Exceptions\BookingArchivalException;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditBooking extends EditRecord
{
    protected static string $resource = BookingResource::class;

    /**
     * No DeleteAction/ForceDeleteAction exists here at all (Phase
     * 17U.1) — a booking is never deleted through the application.
     * Archive/Restore delegate to BookingArchivalService exclusively;
     * this page never calls $record->delete()/forceDelete() directly.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('archive')
                ->label('Archive Booking')
                ->icon('heroicon-m-archive-box')
                ->color('danger')
                ->authorize(fn (Booking $record): bool => auth()->user()?->can('archive', $record) ?? false)
                ->visible(fn (Booking $record): bool => ! $record->trashed() && $record->status->isTerminal())
                ->form([
                    Textarea::make('reason')
                        ->label('Archival reason')
                        ->helperText('This booking, its lesson, and every dependent attendance, financial, review, and feedback record are permanently preserved and remain visible to authorized administrators.')
                        ->required()
                        ->maxLength(500),
                ])
                ->action(function (Booking $record, array $data): void {
                    $this->archiveOrRestore(fn (BookingArchivalServiceInterface $service) => $service->archive($record, auth()->user(), $data['reason']), 'Booking archived');
                }),
            Action::make('restore')
                ->label('Restore Booking')
                ->icon('heroicon-m-arrow-uturn-left')
                ->color('success')
                ->authorize(fn (Booking $record): bool => auth()->user()?->can('restore', $record) ?? false)
                ->visible(fn (Booking $record): bool => $record->trashed())
                ->form([
                    Textarea::make('reason')
                        ->label('Restoration reason')
                        ->required()
                        ->maxLength(500),
                ])
                ->action(function (Booking $record, array $data): void {
                    $this->archiveOrRestore(fn (BookingArchivalServiceInterface $service) => $service->restore($record, auth()->user(), $data['reason']), 'Booking restored');
                }),
        ];
    }

    /** Converts domain failures into a friendly notification — never a raw SQL/authorization exception reaching the panel. */
    private function archiveOrRestore(callable $callback, string $successTitle): void
    {
        try {
            $callback(app(BookingArchivalServiceInterface::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (BookingArchivalException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        } catch (AuthorizationException $e) {
            Notification::make()->title('Not authorized')->body($e->getMessage())->danger()->send();
        }
    }
}
