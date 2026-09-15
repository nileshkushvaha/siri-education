<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Booking\Services\RecordingService;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Recording;
use Illuminate\Console\Command;

/**
 * READ-ONLY inspection of one booking's recording for support:
 * status, failure code, attempts, provider/meeting consistency,
 * whether a stored locator exists (never its value), lifecycle
 * timestamps, the retry decision, and the recording audit events.
 * Writes nothing, retries nothing, prints no locator, URL, token or
 * exception text.
 *
 *     php artisan recordings:inspect BK-XDHJAJ80WY
 */
final class InspectRecording extends Command
{
    protected $signature = 'recordings:inspect {booking : Booking reference (BK-…) or id}';

    protected $description = 'Read-only: inspect the recording of one booking — status, failure, attempts, provider consistency, locator presence, timestamps, audit events';

    public function handle(RecordingService $recordings): int
    {
        $needle = (string) $this->argument('booking');
        $booking = Booking::query()
            ->with(['meeting', 'recording.bookingMeeting', 'lesson'])
            ->where('reference', $needle)
            ->orWhere('id', $needle)
            ->first();

        if ($booking === null) {
            $this->components->error("No booking found for '{$needle}'.");

            return self::FAILURE;
        }

        $recording = $booking->recording;
        $meeting = $booking->meeting;

        $this->components->twoColumnDetail('Booking', sprintf('%s · %s · %s → %s UTC', $booking->reference, $booking->status->value, $booking->starts_at?->utc()->toDateTimeString() ?? '—', $booking->ends_at?->utc()->toDateTimeString() ?? '—'));
        $this->components->twoColumnDetail('Lesson outcome', sprintf('%s%s', $booking->lesson?->outcome->value ?? 'no lesson row', $booking->lesson?->outcome_finalized_at !== null ? ' (finalized)' : ''));
        $this->components->twoColumnDetail('Current meeting', $meeting === null ? 'none' : sprintf('%s · %s · id %s · closed %s', $meeting->provider, $meeting->status->value, $meeting->id, $meeting->metadata['closed_at'] ?? 'no'));

        if ($recording === null) {
            $this->components->warn('No recording row exists for this booking. Eligibility at meeting-creation time decides registration; the application log carries "Lesson recording not registered." with the reason.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('Recording', sprintf('%s · status %s', $recording->id, $recording->status->value));
        $this->components->twoColumnDetail('Failure code', $recording->failure_code?->value ?? '—');
        $this->components->twoColumnDetail('Failure meaning', $recording->failure_code?->label() ?? '—');
        $this->components->twoColumnDetail('Capture attempts', (string) $recording->capture_attempts);
        $this->components->twoColumnDetail('Recording provider / meeting provider', sprintf('%s / %s%s', $recording->provider, $recording->bookingMeeting?->provider ?? '—', $recording->bookingMeeting !== null && $recording->bookingMeeting->provider !== $recording->provider ? '  <fg=red>MISMATCH</>' : ''));
        $this->components->twoColumnDetail('Recording meeting id / booking meeting id', sprintf('%s / %s%s', $recording->booking_meeting_id ?? '—', $meeting?->id ?? '—', $meeting !== null && $recording->booking_meeting_id !== $meeting->id ? '  <fg=red>DIFFERENT MEETING</>' : ''));
        $this->components->twoColumnDetail('Source', $recording->isManuallyAttached() ? 'attached by operator' : 'pipeline');
        $this->components->twoColumnDetail('Provider reference', $recording->provider_reference !== null ? 'present' : 'none');
        $this->components->twoColumnDetail('Storage backend / locator', sprintf('%s / %s', $recording->storage_driver ?? '—', $recording->storage_path !== null ? 'present' : 'none'));
        $this->components->twoColumnDetail('Size / duration / format', sprintf('%s / %s / %s', $recording->size_bytes !== null ? number_format($recording->size_bytes / 1048576, 1).' MB' : '—', $recording->duration_seconds !== null ? gmdate('H:i:s', $recording->duration_seconds) : '—', $recording->mime_type ?? '—'));
        $this->components->twoColumnDetail('Playable (admin download possible)', $recording->isPlayable() ? 'yes' : 'no');
        $this->components->twoColumnDetail('Student access', $recording->isStudentAccessWithheld() ? 'withheld since '.$recording->student_access_revoked_at?->utc()->toDateTimeString() : 'per platform policy');

        $this->newLine();

        foreach ([
            'created_at' => 'Registered',
            'recorded_at' => 'Recorded',
            'transfer_started_at' => 'Transfer started',
            'stored_at' => 'Stored',
            'available_at' => 'Available',
            'failed_at' => 'Failed',
            'expires_at' => 'Expires',
            'updated_at' => 'Last change',
        ] as $column => $label) {
            $this->components->twoColumnDetail($label, $recording->{$column}?->utc()->toDateTimeString().($recording->{$column} !== null ? ' UTC' : '—'));
        }

        $this->newLine();
        $refusal = $recordings->retryRefusalReason($recording);
        $this->components->twoColumnDetail('Ordinary retry', match (true) {
            $recording->status->value !== 'failed' => 'not applicable (not failed)',
            $refusal !== null => '<fg=red>refused</> — '.$refusal,
            default => 'allowed (admin action "Retry ingestion")',
        });

        if ($recording->failure_code !== null) {
            $this->components->twoColumnDetail('Guidance', $recording->failure_code->operatorGuidance());
        }

        $events = Activity::query()
            ->where('log_name', 'recordings')
            ->where('subject_type', Recording::class)
            ->where('subject_id', $recording->getKey())
            ->orderBy('created_at')
            ->get(['event', 'created_at', 'properties']);

        $this->newLine();
        $this->components->twoColumnDetail('Audit events', (string) $events->count());

        foreach ($events as $event) {
            $props = collect($event->properties ?? [])
                ->only(['failure_code', 'previous_failure_code', 'attempt', 'provider', 'from_provider', 'to_provider', 'reason_code'])
                ->map(fn ($v, $k): string => $k.'='.(is_scalar($v) ? (string) $v : json_encode($v)))
                ->implode(' ');
            $this->line(sprintf('  %s  %-42s %s', $event->created_at->utc()->toDateTimeString(), $event->event, $props));
        }

        $this->newLine();
        $this->components->info('Read-only. Nothing was changed.');

        return self::SUCCESS;
    }
}
