<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recordings\Actions;

use App\Booking\Enums\RecordingStatus;
use App\Booking\Jobs\CaptureLessonRecordingJob;
use App\Booking\Services\RecordingService;
use App\Models\Recording;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The single administrative write path into a recording, and the only
 * manual recovery the SRS's "record the failure and notify
 * administrators" (§12.35) actually needs.
 *
 * Safety properties, all enforced below the UI rather than by hiding
 * the button:
 *
 *  - AUTHORIZED — RecordingService::retryFailed() calls
 *    RecordingPolicy::retry(), so a crafted request is refused even
 *    though the action is also hidden for unauthorized users.
 *  - AUDITED — every retry is written to the audit trail with the
 *    acting admin and the previous failure code.
 *  - IDEMPOTENT — only a Failed row transitions, under a row lock. A
 *    double-click, or a retry racing the reconciliation sweep, cannot
 *    start two ingestions or produce a second stored object.
 *  - NON-DESTRUCTIVE — it never deletes, and never re-uploads over an
 *    object that already exists: a row holding a storage locator is
 *    Stored or Available, and neither state is retryable.
 */
final class RetryRecordingIngestionAction
{
    public static function make(): Action
    {
        return Action::make('retryIngestion')
            ->label('Retry ingestion')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Retry recording ingestion')
            ->modalDescription('The recording will be fetched from the meeting provider and stored again. Existing stored recordings are never affected.')
            ->visible(fn (Recording $record): bool => $record->status === RecordingStatus::Failed
                && auth()->user()?->can('retry', $record) === true)
            ->action(function (Recording $record, RecordingService $recordings): void {
                $queued = $recordings->retryFailed($record, auth()->user());

                if (! $queued) {
                    Notification::make()
                        ->title('Nothing to retry')
                        ->body('This recording is no longer in a failed state.')
                        ->warning()
                        ->send();

                    return;
                }

                // Queued, never inline: an admin click must not hold an
                // HTTP request open for the length of a video transfer.
                CaptureLessonRecordingJob::dispatch($record->getKey());

                Notification::make()
                    ->title('Retry queued')
                    ->body('The recording will be fetched and stored in the background.')
                    ->success()
                    ->send();
            });
    }
}
