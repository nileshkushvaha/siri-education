<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\DTOs\ProviderRecordingResult;
use App\Booking\Enums\RecordingStatus;
use App\Booking\Jobs\CaptureLessonRecordingJob;
use App\Booking\Meetings\FakeMeetingProvider;
use App\Booking\Registry\MeetingProviderRegistry;
use App\Booking\Services\RecordingService;
use App\Models\Recording;
use App\Notifications\Booking\RecordingAvailableNotification;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GAP-028 requirement #5 — the queued job and the reconciliation sweep
 * command both funnel through RecordingService::capture(), so a
 * duplicate job dispatch or a sweep re-processing an already-settled
 * row can never import twice. Also proves the sweep's query stays
 * bounded as the recordings table grows (mirrors the flat-query-count
 * pattern from Phase 39's RecommendationServiceTest).
 */
final class RecordingCaptureJobAndSweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        FakeMeetingProvider::reset();
    }

    /** Minimal bytes finfo genuinely detects as video/mp4 — Recording's media collection validates real detected mime. */
    private function fakeMp4Bytes(): string
    {
        return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 200);
    }

    private function pendingRecording(): Recording
    {
        $recording = Recording::factory()->create(['provider' => FakeMeetingProvider::KEY]);
        $recording->bookingMeeting->update(['ends_at' => now()->subHours(2), 'provider' => FakeMeetingProvider::KEY]);

        return $recording->fresh();
    }

    public function test_running_the_capture_job_twice_never_imports_the_recording_twice(): void
    {
        $recording = $this->pendingRecording();

        FakeMeetingProvider::$nextRecordingResult = new ProviderRecordingResult(
            providerReference: 'ref-1',
            content: $this->fakeMp4Bytes(),
            filename: 'lesson.mp4',
            mimeType: 'video/mp4',
            durationSeconds: 600,
            recordedAt: CarbonImmutable::now(),
        );

        (new CaptureLessonRecordingJob($recording->id))->handle(
            app(RecordingService::class),
            app(MeetingProviderRegistry::class),
        );
        // Simulate a duplicate provider callback / redelivered queue job.
        (new CaptureLessonRecordingJob($recording->id))->handle(
            app(RecordingService::class),
            app(MeetingProviderRegistry::class),
        );

        $this->assertSame(1, Recording::query()->count());
        $this->assertSame(RecordingStatus::Available, $recording->fresh()->status);
        Notification::assertSentToTimes($recording->fresh()->student, RecordingAvailableNotification::class, 1);
    }

    public function test_capture_job_is_a_no_op_when_the_recording_no_longer_exists(): void
    {
        (new CaptureLessonRecordingJob((string) Str::uuid()))->handle(
            app(RecordingService::class),
            app(MeetingProviderRegistry::class),
        );

        $this->assertSame(0, Recording::query()->count());
    }

    public function test_capture_sweep_command_processes_due_recordings_within_the_window(): void
    {
        $meetings = app(MeetingSettings::class);
        $meetings->recording_capture_delay_minutes = 15;
        $meetings->recording_capture_max_age_hours = 168;
        $meetings->save();

        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingResult = new ProviderRecordingResult(
            providerReference: 'ref-sweep',
            content: $this->fakeMp4Bytes(),
            filename: 'lesson.mp4',
            mimeType: 'video/mp4',
            durationSeconds: 600,
            recordedAt: CarbonImmutable::now(),
        );

        $this->artisan('recordings:capture')->assertSuccessful();

        $this->assertSame(RecordingStatus::Available, $recording->fresh()->status);
    }

    public function test_capture_sweep_command_skips_meetings_too_old_for_the_capture_window(): void
    {
        $meetings = app(MeetingSettings::class);
        $meetings->recording_capture_max_age_hours = 1;
        $meetings->save();

        $recording = Recording::factory()->create(['provider' => FakeMeetingProvider::KEY]);
        $recording->bookingMeeting->update(['ends_at' => now()->subDays(10), 'provider' => FakeMeetingProvider::KEY]);

        FakeMeetingProvider::$nextRecordingResult = new ProviderRecordingResult(
            providerReference: 'ref-too-old',
            content: 'binary-content',
            filename: 'lesson.mp4',
            mimeType: 'video/mp4',
            durationSeconds: 600,
            recordedAt: CarbonImmutable::now(),
        );

        $this->artisan('recordings:capture')->assertSuccessful();

        $this->assertSame(RecordingStatus::Pending, $recording->fresh()->status);
    }

    /**
     * The sweep's candidate-listing query is what must stay bounded as
     * the table grows — each due (Pending) row genuinely needs its own
     * row-locked transaction inside capture(), so query count scales
     * with the number of DUE rows, never with unrelated rows already
     * settled (Available/Expired/Failed) sitting in the same table.
     */
    public function test_capture_sweep_query_count_is_unaffected_by_unrelated_settled_recordings(): void
    {
        FakeMeetingProvider::$nextRecordingResult = null; // stays Pending — isolates the fetch/candidate query shape

        // A single attempt is enough to exhaust each row's eligibility for
        // this run's own filter, so a later sweep never re-processes it —
        // isolating each measurement to only its own freshly-due rows.
        $meetings = app(MeetingSettings::class);
        $meetings->recording_capture_max_attempts = 1;
        $meetings->save();

        for ($i = 0; $i < 3; $i++) {
            $this->pendingRecording();
        }

        DB::enableQueryLog();
        $this->artisan('recordings:capture')->assertSuccessful();
        $withoutNoise = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        // Add a large number of already-settled recordings unrelated to
        // this run's due candidates — the sweep must never scan these.
        Recording::factory()->count(40)->available()->create();
        Recording::factory()->count(40)->expired()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->pendingRecording();
        }

        DB::enableQueryLog();
        $this->artisan('recordings:capture')->assertSuccessful();
        $withNoise = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        $this->assertSame($withoutNoise, $withNoise, 'sweep query count should not grow from unrelated settled recordings in the table');
    }

    public function test_expire_sweep_command_delegates_to_the_configured_batch_size(): void
    {
        $meetings = app(MeetingSettings::class);
        $meetings->recording_expiry_batch_size = 2;
        $meetings->save();

        Recording::factory()->count(3)->available()->create(['expires_at' => now()->subMinute()]);

        $this->artisan('recordings:expire')->assertSuccessful();

        $this->assertSame(2, Recording::query()->where('status', RecordingStatus::Expired)->count());
        $this->assertSame(1, Recording::query()->where('status', RecordingStatus::Available)->count());
    }
}
