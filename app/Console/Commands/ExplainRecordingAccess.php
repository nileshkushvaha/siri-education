<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Booking\Enums\RecordingStatus;
use App\Booking\Services\RecordingPlaybackAccessResolver;
use App\Country\Enums\CountryFeature;
use App\Country\Services\CountryFeatureResolver;
use App\Country\Services\CountryResolver;
use App\Exceptions\Student\StudentActionNotAvailableException;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Services\Student\StudentLifecycleService;
use App\Settings\FeatureSettings;
use App\Settings\MeetingSettings;
use Illuminate\Console\Command;

/**
 * Read-only: walks every gate RecordingPlaybackAccessResolver applies for
 * ONE booking and prints which is open and which is closed, in order.
 *
 * "The recording is in Drive but the student cannot see it" has six
 * possible causes and the student-facing UI deliberately shows nothing
 * for most of them (a Hidden state never explains itself). Support needs
 * the explanation without a database client:
 *
 *     php artisan recordings:explain BK-6QEOFCJ6NB
 */
final class ExplainRecordingAccess extends Command
{
    protected $signature = 'recordings:explain {booking : Booking reference (BK-…) or id}';

    protected $description = 'Explain, gate by gate, why the student of a booking can or cannot watch its recording.';

    public function handle(
        RecordingPlaybackAccessResolver $access,
        MeetingSettings $meetings,
        FeatureSettings $features,
        CountryFeatureResolver $countryFeatures,
        CountryResolver $countries,
        StudentLifecycleService $lifecycle,
    ): int {
        $needle = (string) $this->argument('booking');
        $booking = Booking::query()
            ->with(['student.profile', 'lesson', 'recording', 'meeting'])
            ->where('reference', $needle)
            ->orWhere('id', $needle)
            ->first();

        if ($booking === null) {
            $this->error("No booking found for '{$needle}'.");

            return self::FAILURE;
        }

        $student = $booking->student;
        $rows = [];
        $note = fn (string $gate, bool $ok, string $detail): array => [$ok ? 'OPEN' : 'CLOSED', $gate, $detail];

        // 1. platform switches
        $rows[] = $note('Students Can Watch Their Recordings (Meeting Settings)', $meetings->recording_student_playback_enabled,
            $meetings->recording_student_playback_enabled ? 'on' : 'OFF — nothing about recordings is shown to any student');

        $country = $student ? $countries->forStudent($student) : null;
        $featureOn = $countryFeatures->isEnabled(CountryFeature::RecordingAvailability, $country);
        $rows[] = $note('Recording feature flag for the student\'s country (Platform Foundation → Recording)', $featureOn,
            sprintf('features.recording_enabled=%s, country=%s', $features->recording_enabled ? 'on' : 'off', $country?->name ?? 'unknown'));

        // 2. viewer
        $lifecycleOk = false;
        $lifecycleDetail = 'no student on booking';
        if ($student !== null) {
            try {
                $lifecycle->assertEligibleForStudentAction($student);
                $lifecycleOk = true;
                $lifecycleDetail = sprintf('%s (#%d) is an active student', $student->email, $student->id);
            } catch (StudentActionNotAvailableException $e) {
                $lifecycleDetail = $e->getMessage();
            }
        }
        $rows[] = $note('Student account in good standing', $lifecycleOk, $lifecycleDetail);

        // 3. lesson delivered
        $lesson = $booking->lesson;
        $delivered = $access->lessonWasDelivered($lesson);
        $rows[] = $note('Lesson finalized as Completed', $delivered, $lesson === null
            ? 'no lesson row for this booking (created on confirmation)'
            : sprintf('lesson status=%s outcome=%s finalized_at=%s — booking status=%s, ended %s',
                $lesson->status->value,
                $lesson->outcome?->value ?? 'none',
                $lesson->outcome_finalized_at?->toDateTimeString() ?? 'never',
                $booking->status->value,
                $booking->ends_at?->diffForHumans() ?? '?'));

        if (! $delivered && $lesson !== null && $lesson->outcome !== LessonOutcome::Completed) {
            $rows[] = ['NOTE', 'Auto-completion', sprintf('lessons:auto-complete finalizes %d minutes after the lesson ends (Platform Foundation → Auto-completion Delay); until then the recording stays hidden.', app(\App\Settings\LessonSettings::class)->auto_complete_grace_minutes)];
        }

        // 4. recording row
        $recording = $booking->recording;
        if ($recording === null) {
            $rows[] = $note('Recording registered', false, $booking->meeting === null
                ? 'no meeting was ever created for this booking, so no recording could be captured'
                : sprintf('meeting exists (%s, %s) but no recording row — capture never ran; check meeting.recording_enabled, the provider recording flag, and that a queue worker processes the "%s" queue', $booking->meeting->provider ?? '?', $booking->meeting->status?->value ?? '?', config('recordings.queue', 'recordings')));
        } else {
            $rows[] = $note('Recording registered', true, sprintf('status=%s driver=%s path=%s size=%s',
                $recording->status->value, $recording->storage_driver ?? 'null', $recording->storage_path ?? 'null', $recording->size_bytes ?? '?'));
            $rows[] = $note('Recording ingested (Available with a stored object)', $recording->isPlayable(), match ($recording->status) {
                RecordingStatus::Pending => 'pending — capture job not run yet (queue worker / recordings:capture schedule)',
                RecordingStatus::Transferring => 'transferring from the provider',
                RecordingStatus::Stored => 'stored, awaiting verification',
                RecordingStatus::Available => $recording->isPlayable() ? 'ready' : 'Available but storage_driver/storage_path missing',
                RecordingStatus::Failed => 'capture FAILED permanently — see recordings admin',
                RecordingStatus::Expired => 'expired per retention',
            });
            $rows[] = $note('Not withheld by an administrator', ! $recording->isStudentAccessWithheld(),
                $recording->isStudentAccessWithheld() ? 'withheld at '.$recording->student_access_revoked_at : 'not withheld');
        }

        $this->info(sprintf('Booking %s — %s, %s', $booking->reference, $booking->type?->name ?? 'session', $booking->starts_at?->toDayDateTimeString()));
        $this->table(['Gate', 'Check', 'Detail'], $rows);

        $state = $student ? $access->stateFor($booking, $student)->value : 'n/a';
        $this->newLine();
        $this->line("Student currently sees: <options=bold>{$state}</>".($state === 'hidden' ? ' (nothing at all)' : ''));

        $closed = collect($rows)->firstWhere(0, 'CLOSED');
        if ($closed !== null) {
            $this->warn('First closed gate: '.$closed[1]);
        }

        return self::SUCCESS;
    }
}
