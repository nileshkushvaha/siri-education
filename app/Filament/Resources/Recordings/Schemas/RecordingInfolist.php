<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recordings\Schemas;

use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Enums\RecordingStatus;
use App\Filament\Resources\Recordings\Support\RecordingStatePresenter;
use App\Models\Recording;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Operational visibility for admins. Deliberately shows the storage
 * BACKEND and never the locator: a Drive file id (or S3 key) on an
 * admin screen is an out-of-band pointer to private student video,
 * and it is not needed to diagnose anything. No credential, token,
 * provider download URL or raw exception appears here or anywhere in
 * this resource. Sentences come from RecordingStatePresenter; the
 * decisions behind them from RecordingService and the model.
 */
class RecordingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Status')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (RecordingStatus $state): string => $state->label())
                            ->color(fn (RecordingStatus $state): string => $state->color()),
                        TextEntry::make('capture_attempts')
                            ->label('Capture attempts')
                            ->state(fn (Recording $record, RecordingStatePresenter $presenter): string => $presenter->attemptsLabel($record)),
                    ]),
                    TextEntry::make('state_summary')
                        ->label('What this means')
                        ->state(fn (Recording $record, RecordingStatePresenter $presenter): string => $presenter->stateSummary($record)),
                    TextEntry::make('failure_code')
                        ->label('Failure')
                        // The stable label, never a raw exception message.
                        ->formatStateUsing(fn (RecordingFailureCode $state): string => $state->label().($state->isPermanent() ? ' (permanent)' : ' (transient)'))
                        ->visible(fn (Recording $record): bool => $record->failure_code !== null),
                    TextEntry::make('next_step')
                        ->label('Next step')
                        ->state(fn (Recording $record, RecordingStatePresenter $presenter): string => $presenter->nextStep($record))
                        ->color(fn (Recording $record, RecordingStatePresenter $presenter): string => $presenter->needsOperatorRecovery($record) ? 'danger' : 'gray'),
                ]),
            Section::make('Lesson')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('booking.reference')->label('Booking'),
                        TextEntry::make('student.name')->label('Student'),
                        TextEntry::make('teacher.name')->label('Instructor'),
                    ]),
                    Grid::make(3)->schema([
                        TextEntry::make('provider'),
                        TextEntry::make('bookingMeeting.provider')
                            ->label('Meeting provider')
                            ->placeholder('—')
                            ->color(fn (Recording $record): string => $record->bookingMeeting !== null && $record->bookingMeeting->provider !== $record->provider ? 'danger' : 'gray')
                            ->helperText(fn (Recording $record): ?string => $record->bookingMeeting !== null && $record->bookingMeeting->provider !== $record->provider
                                ? 'Differs from the recording provider: the meeting was replaced. See recordings:reconcile-provider.'
                                : null),
                        TextEntry::make('duration_seconds')
                            ->label('Duration')
                            ->formatStateUsing(fn (?int $state): ?string => $state !== null ? gmdate($state >= 3600 ? 'H:i:s' : 'i:s', $state) : null)
                            ->placeholder('—'),
                    ]),
                ]),
            Section::make('Lifecycle')
                ->description('All times in your timezone. A timestamp that is missing simply has not happened.')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('created_at')->label('Registered')->dateTime(),
                        TextEntry::make('recorded_at')->label('Recorded')->dateTime()->placeholder('—'),
                        TextEntry::make('transfer_started_at')->label('Transfer started')->dateTime()->placeholder('—'),
                        TextEntry::make('stored_at')->label('Stored')->dateTime()->placeholder('—'),
                    ]),
                    Grid::make(4)->schema([
                        TextEntry::make('available_at')->label('Available')->dateTime()->placeholder('—'),
                        TextEntry::make('failed_at')->label('Failed')->dateTime()->placeholder('—'),
                        TextEntry::make('expires_at')->label('Expires')->dateTime()->placeholder('—'),
                        TextEntry::make('updated_at')->label('Last change')->dateTime(),
                    ]),
                ]),
            Section::make('Stored file')
                ->schema([
                    Grid::make(4)->schema([
                        // The driver only — never storage_path.
                        TextEntry::make('storage_driver')
                            ->label('Storage backend')
                            ->placeholder('—'),
                        TextEntry::make('source')
                            ->label('Source')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === Recording::SOURCE_MANUAL ? 'Attached by operator' : 'Pipeline')
                            ->color(fn (?string $state): string => $state === Recording::SOURCE_MANUAL ? 'warning' : 'gray'),
                        TextEntry::make('stored_object')
                            ->label('Stored object')
                            ->badge()
                            ->state(fn (Recording $record): string => $record->storage_path !== null ? 'present' : 'none')
                            ->color(fn (string $state): string => $state === 'present' ? 'success' : 'gray'),
                        TextEntry::make('size_bytes')
                            ->label('Size')
                            ->formatStateUsing(fn (?int $state): ?string => $state !== null ? number_format($state / 1048576, 1).' MB' : null)
                            ->placeholder('—'),
                        TextEntry::make('mime_type')->label('Format')->placeholder('—'),
                    ]),
                ]),
            Section::make('Student access')
                ->description('Whether the lesson\'s student may watch this recording once it is available. Independent of ingestion: a recording can be withheld before it exists. Governed by the platform playback setting (Settings → Meetings) and this per-recording override.')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('student_access')
                            ->label('Student access')
                            ->badge()
                            ->state(fn (Recording $record): string => $record->isStudentAccessWithheld() ? 'Withheld' : 'Per platform policy')
                            ->color(fn (string $state): string => $state === 'Withheld' ? 'danger' : 'success'),
                        TextEntry::make('student_access_revoked_at')
                            ->label('Withheld since')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('studentAccessRevokedBy.name')
                            ->label('Withheld by')
                            ->placeholder('—'),
                    ]),
                ]),
        ]);
    }
}
