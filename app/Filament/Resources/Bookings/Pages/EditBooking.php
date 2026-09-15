<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Pages;

use App\Booking\Contracts\BookingArchivalServiceInterface;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingArchivalException;
use App\Booking\Exceptions\RecordingStorageException;
use App\Booking\Services\RecordingService;
use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Support\Presentation\BackAction;
use App\Models\Booking;
use App\Models\Recording;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;

class EditBooking extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = BookingResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Scheduling and status changes happen from the bookings list — this page is a read-only detail view.';
    }

    /**
     * No DeleteAction/ForceDeleteAction exists here at all (Phase
     * 17U.1) — a booking is never deleted through the application.
     * Archive/Restore delegate to BookingArchivalService exclusively;
     * this page never calls $record->delete()/forceDelete() directly.
     */
    /**
     * Manual recording recovery for a lesson the pipeline never registered
     * a recording for: registers the row under an audited override and
     * attaches the operator's file in one step, so the link is pasted once.
     * A lesson that already has a recording is handled from Recordings.
     */
    private function attachRecordingAction(): Action
    {
        return Action::make('attachRecording')
            ->label('Attach recording')
            ->icon('heroicon-o-paper-clip')
            ->color('warning')
            ->visible(fn (Booking $record): bool => auth()->user()?->can('attach', Recording::class) === true
                && ! $record->trashed()
                && $record->meeting !== null
                && $record->recording === null
                && in_array($record->status, [BookingStatus::Confirmed, BookingStatus::Completed], true)
                && app(RecordingService::class)->supportsManualAttach())
            ->modalHeading('Attach a recording to this lesson')
            ->modalDescription('No recording was registered for this lesson by the pipeline. Paste the file\'s link or id from the platform recording storage; a recording record is created, the file is copied, verified and published in the background, and the student can watch it once the lesson is marked complete. The original file is never moved or deleted.')
            ->form([
                TextInput::make('reference')
                    ->label('File link or id')
                    ->required()
                    ->maxLength(2048)
                    ->helperText('Must be readable by the platform meeting account.'),
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->minLength(5)
                    ->maxLength(500)
                    ->rows(3)
                    ->helperText('Recorded on the audit trail with your name.'),
            ])
            ->action(function (Booking $record, array $data, RecordingService $recordings): void {
                try {
                    $recording = $recordings->registerManual($record, auth()->user());
                    $recordings->attachExternal($recording, auth()->user(), (string) $data['reference'], (string) $data['reason'], registeredByOverride: true);
                } catch (RecordingStorageException|InvalidArgumentException $e) {
                    Notification::make()->title('Recording not attached')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title('Attach queued')
                    ->body('The recording was registered and the file will be copied, verified and published in the background.')
                    ->success()
                    ->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Bookings'),
            $this->attachRecordingAction(),
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
        ]);
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
