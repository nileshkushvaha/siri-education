<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Alerts\DTOs\OperationalAlertSignal;
use App\Alerts\Enums\OperationalAlertCategory;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertType;
use App\Alerts\Services\OperationalAlertService;
use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\Contracts\MeetingRecordingProviderInterface;
use App\Booking\Enums\RecordingStatus;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\Recording;
use App\Models\User;
use App\Notifications\Booking\RecordingAvailableNotification;
use App\Services\AuditTrailService;
use App\Services\Notifications\NotificationIdempotencyGuard;
use App\Settings\MeetingSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GAP-028 (SRS §12.18-21/31) — the single authoritative boundary for
 * the recording pipeline: eligibility (via RecordingEligibilityResolver),
 * provider capture/import, private storage (Media Library, 'local'
 * disk — never public), access authorization, and retention/expiry.
 * Neither BookingMeetingService, a provider, nor a Filament resource
 * ever writes to the `recordings` table directly.
 */
final class RecordingService
{
    public function __construct(
        private readonly RecordingEligibilityResolver $eligibility,
        private readonly AuditTrailService $audit,
        private readonly OperationalAlertService $alerts,
        private readonly NotificationIdempotencyGuard $notifications,
        private readonly MeetingSettings $settings,
    ) {}

    /**
     * Called once, right after a meeting is created for a confirmed
     * booking (BookingMeetingService::createMeeting()). Idempotent via
     * the unique idempotency_key — a retried/duplicate call for the
     * same meeting can never create a second row.
     */
    public function registerIfEligible(Booking $booking, BookingMeeting $meeting, MeetingProviderInterface $provider): ?Recording
    {
        $result = $this->eligibility->evaluate($booking, $provider);

        if (! $result->eligible) {
            return null;
        }

        $idempotencyKey = 'recording:'.$meeting->id;

        $existing = Recording::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $recording = Recording::query()->create([
                'booking_meeting_id' => $meeting->id,
                'booking_id' => $booking->id,
                'student_id' => $booking->student_id,
                'teacher_id' => $booking->instructor_id,
                'provider' => $provider->key(),
                'status' => RecordingStatus::Pending,
                'idempotency_key' => $idempotencyKey,
                'consent_snapshot' => [
                    'student_consented' => (bool) $booking->student?->profile?->consents_to_recording,
                    'instructor_consented' => (bool) $booking->instructor?->profile?->consents_to_recording,
                    'snapshotted_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (QueryException $e) {
            // A concurrent registration (e.g. an update() retry racing
            // the original create()) already won the unique index —
            // the idempotent outcome, not a failure.
            if (str_contains($e->getMessage(), 'recordings_idempotency_key_unique') || str_contains($e->getMessage(), 'idempotency_key')) {
                return Recording::query()->where('idempotency_key', $idempotencyKey)->first();
            }

            throw $e;
        }

        $this->audit->logSystem(
            'recordings',
            'recording_registered',
            'Lesson recording registered as eligible.',
            $recording,
            ['booking_meeting_id' => $meeting->id],
        );

        return $recording;
    }

    /**
     * Attempts one capture cycle for a single Pending recording —
     * called by CaptureLessonRecordingJob (queued, after-commit) and by
     * the recordings:capture sweep command (reconciliation). Both
     * paths share this exact method, so a duplicate/replayed job can
     * never produce a duplicate import: once the row leaves Pending,
     * every subsequent call is a no-op.
     */
    public function capture(Recording $recording, MeetingProviderInterface $provider): void
    {
        DB::transaction(function () use ($recording, $provider): void {
            /** @var Recording $fresh */
            $fresh = Recording::query()->whereKey($recording->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== RecordingStatus::Pending) {
                return; // already settled — nothing to do
            }

            if (! $provider instanceof MeetingRecordingProviderInterface || ! $provider->supportsRecording()) {
                $this->fail($fresh, 'provider_capability_missing');

                return;
            }

            $fresh->increment('capture_attempts');

            try {
                $result = $provider->fetchRecording($fresh->bookingMeeting);
            } catch (Throwable $e) {
                $this->recordTransientFailure($fresh, $e);

                return;
            }

            if ($result === null) {
                return; // not ready yet — a future sweep will retry within the window
            }

            $fresh->addMediaFromString($result->content)
                ->usingFileName($result->filename)
                ->toMediaCollection('file');

            $fresh->fill([
                'status' => RecordingStatus::Available,
                'provider_reference' => $result->providerReference,
                'duration_seconds' => $result->durationSeconds,
                'size_bytes' => strlen($result->content),
                'mime_type' => $result->mimeType,
                'recorded_at' => $result->recordedAt,
                'available_at' => now(),
                'expires_at' => now()->addDays(max(1, $this->settings->recording_retention_days)),
            ])->save();

            $this->audit->logSystem(
                'recordings',
                'recording_available',
                'Lesson recording captured and stored.',
                $fresh,
                ['provider' => $fresh->provider],
            );

            $this->notifyAvailable($fresh);
        });
    }

    /** @return int number of recordings transitioned to Expired */
    public function expireDueRecordings(int $batchSize): int
    {
        $expired = 0;

        Recording::query()->dueForExpiry()->limit(max(1, $batchSize))->get()->each(function (Recording $recording) use (&$expired): void {
            DB::transaction(function () use ($recording): void {
                /** @var Recording $fresh */
                $fresh = Recording::query()->whereKey($recording->getKey())->lockForUpdate()->firstOrFail();

                if ($fresh->status !== RecordingStatus::Available) {
                    return;
                }

                // Metadata (duration/size/mime/timestamps) stays on the
                // row — only the media FILE is removed (requirement #7).
                $fresh->clearMediaCollection('file');
                $fresh->fill(['status' => RecordingStatus::Expired])->save();

                $this->audit->logSystem(
                    'recordings',
                    'recording_expired',
                    'Lesson recording expired; media deleted, metadata retained.',
                    $fresh,
                    [],
                );
            });

            $expired++;
        });

        return $expired;
    }

    /**
     * The RecordingPolicy IS the authorization source of truth (project
     * convention — "Policies handle authorization"); this method is the
     * requirement #4 "access authorization" entry point the download
     * controller and any other caller use, so nobody calls Gate
     * directly and drifts from the policy.
     *
     * @throws AuthorizationException
     */
    public function assertCanAccess(User $viewer, Recording $recording): void
    {
        Gate::forUser($viewer)->authorize('view', $recording);
    }

    // ── Internals ──────────────────────────────────────────────────────

    private function fail(Recording $recording, string $failureCode): void
    {
        $recording->fill([
            'status' => RecordingStatus::Failed,
            'failure_code' => $failureCode,
            'failed_at' => now(),
        ])->save();

        $this->raiseFailureAlert($recording, $failureCode);
    }

    private function recordTransientFailure(Recording $recording, Throwable $e): void
    {
        $withinRetryWindow = now()->lessThanOrEqualTo(
            $recording->bookingMeeting->ends_at->addMinutes(max(0, $this->settings->recording_capture_retry_minutes)),
        );
        $attemptsLeft = $recording->capture_attempts < max(1, $this->settings->recording_capture_max_attempts);

        Log::warning('Recording capture failed', [
            'recording_id' => $recording->id,
            'provider' => $recording->provider,
            'attempts' => $recording->capture_attempts,
            'error' => $e->getMessage(),
        ]);

        if ($withinRetryWindow && $attemptsLeft) {
            return; // stays Pending — the next sweep retries it
        }

        $this->fail($recording, 'capture_retries_exhausted');
    }

    private function raiseFailureAlert(Recording $recording, string $failureCode): void
    {
        $this->alerts->createOrMerge(new OperationalAlertSignal(
            type: OperationalAlertType::RecordingCaptureFailed,
            category: OperationalAlertCategory::BookingMeeting,
            severity: OperationalAlertSeverity::Warning,
            title: 'Lesson recording capture failed',
            summary: sprintf('Recording capture failed for booking %s after %d attempt(s): %s', $recording->booking_id, $recording->capture_attempts, $failureCode),
            subjectType: Recording::class,
            subjectId: $recording->id,
            metadata: ['failure_code' => $failureCode, 'provider' => $recording->provider],
        ));
    }

    private function notifyAvailable(Recording $recording): void
    {
        foreach ([$recording->student, $recording->teacher] as $participant) {
            if ($participant === null) {
                continue;
            }

            $this->notifications->once(
                sprintf('recording_available:%s:%d', $recording->id, $participant->id),
                RecordingAvailableNotification::class,
                fn () => $participant->notify(new RecordingAvailableNotification($recording)),
            );
        }
    }
}
