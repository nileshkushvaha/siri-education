<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recordings\Actions;

use App\Booking\Services\RecordingService;
use App\Models\Recording;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The per-recording exception to the student playback policy (SRS
 * §12.20 — access rules are configurable). Withholding is:
 *
 *  - AUTHORIZED below the UI — RecordingService::withholdStudentAccess()
 *    calls RecordingPolicy::withhold(), so a crafted request is refused
 *    even though the action is also hidden;
 *  - AUDITED as an override with the mandatory reason and the acting
 *    admin (AuditTrailService::logOverride);
 *  - REVERSIBLE — RestoreStudentAccessAction is its exact inverse;
 *  - NON-DESTRUCTIVE — the object, the lifecycle, retention and
 *    administrator access are untouched. Only the student's watch
 *    right goes away.
 */
final class WithholdStudentAccessAction
{
    public static function make(): Action
    {
        return Action::make('withholdStudentAccess')
            ->label('Withhold from student')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Withhold this recording from its student')
            ->modalDescription('The student will see the recording as unavailable and cannot watch it. Administrator access, storage and retention are unaffected. This can be restored later.')
            ->form([
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->minLength(5)
                    ->maxLength(500)
                    ->rows(3)
                    ->helperText('Recorded on the audit trail with your name. The student does not see it.'),
            ])
            ->visible(fn (Recording $record): bool => ! $record->isStudentAccessWithheld()
                && auth()->user()?->can('withhold', $record) === true)
            ->action(function (Recording $record, array $data, RecordingService $recordings): void {
                $changed = $recordings->withholdStudentAccess($record, auth()->user(), (string) $data['reason']);

                Notification::make()
                    ->title($changed ? 'Student access withheld' : 'Already withheld')
                    ->body($changed
                        ? 'The student can no longer watch this recording.'
                        : 'This recording was already withheld from its student.')
                    ->{$changed ? 'success' : 'warning'}()
                    ->send();
            });
    }
}
