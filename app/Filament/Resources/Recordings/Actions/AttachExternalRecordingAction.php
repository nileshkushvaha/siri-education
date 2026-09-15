<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recordings\Actions;

use App\Booking\Exceptions\RecordingStorageException;
use App\Booking\Services\RecordingService;
use App\Models\Recording;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

/**
 * Manual recovery: an administrator attaches the recording's object by
 * hand — a file the platform storage can read — when the pipeline
 * failed or never delivered it. Same safety properties as Retry:
 *
 *  - AUTHORIZED below the UI — RecordingService::attachExternal() calls
 *    RecordingPolicy::attach(), so a crafted request is refused;
 *  - VALIDATED at the click — the storage backend proves it can read the
 *    reference and that it is a recording before anything is queued, and
 *    its own explanation is what the admin sees on refusal;
 *  - AUDITED as an override with the mandatory reason and the acting admin;
 *  - NON-DESTRUCTIVE — never overwrites a stored object, never touches
 *    the operator's original;
 *  - QUEUED — the copy, verification and publication run in the
 *    background through the ordinary pipeline.
 *
 * Wording here is backend-neutral on purpose; what a valid reference
 * looks like is the storage adapter's business.
 */
final class AttachExternalRecordingAction
{
    public static function make(): Action
    {
        return Action::make('attachExternal')
            ->label('Attach recording file')
            ->icon(Heroicon::OutlinedPaperClip)
            ->modalIcon(Heroicon::OutlinedPaperClip)
            ->color('warning')
            ->modalHeading('Attach a recording file by hand')
            ->modalDescription('Use this when the recording exists but the pipeline could not deliver it. Paste the file\'s link or id from the platform recording storage; the platform must be able to read it. The file is copied into the platform recording area, verified and published in the background — the original is never moved or deleted.')
            ->form([
                TextInput::make('reference')
                    ->label('File link or id')
                    ->required()
                    ->maxLength(2048)
                    ->helperText('A link to the file, or its id. It must be readable by the platform meeting account.'),
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->minLength(5)
                    ->maxLength(500)
                    ->rows(3)
                    ->helperText('Recorded on the audit trail with your name. The student does not see it.'),
            ])
            ->visible(fn (Recording $record, RecordingService $recordings): bool => auth()->user()?->can('attach', Recording::class) === true
                && $recordings->attachRefusalReason($record) === null)
            ->action(function (Recording $record, array $data, RecordingService $recordings): void {
                try {
                    $recordings->attachExternal($record, auth()->user(), (string) $data['reference'], (string) $data['reason']);
                } catch (RecordingStorageException|InvalidArgumentException $e) {
                    Notification::make()
                        ->title('Recording not attached')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Attach queued')
                    ->body('The file will be copied, verified and published in the background. Refresh in a minute to see the result.')
                    ->success()
                    ->send();
            });
    }
}
