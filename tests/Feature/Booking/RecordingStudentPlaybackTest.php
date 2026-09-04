<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\RecordingStatus;
use App\Booking\Storage\FilesystemRecordingStorage;
use App\Enums\StudentStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\Recording;
use App\Models\User;
use App\Settings\FeatureSettings;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Student playback of a lesson recording (SRS §12.20), request by
 * request. Two things are proven here:
 *
 *  1. WHO. Only the recording's own student — and only while student
 *     playback is enabled, the recording is serveable, and it has not
 *     been withheld. Every other identity, and every guessed id, is
 *     refused; the refusal is audited.
 *  2. WHAT. The stream is seekable (206 + Content-Range), inline, never
 *     cacheable by a shared proxy, and reveals nothing about where the
 *     bytes live. The page carries no provider or storage detail and
 *     no download.
 */
final class RecordingStudentPlaybackTest extends TestCase
{
    use RefreshDatabase;

    private const string CONTENT = '0123456789abcdefghijklmnopqrstuvwxyz';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->setPlayback(true);
    }

    private function setPlayback(bool $enabled, bool $featureFlag = true): void
    {
        $features = app(FeatureSettings::class);
        $features->recording_enabled = $featureFlag;
        $features->save();

        $meeting = app(MeetingSettings::class);
        $meeting->recording_enabled = true;
        $meeting->recording_student_playback_enabled = $enabled;
        $meeting->save();
    }

    private function activeStudent(): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Active]);

        return $student;
    }

    private function activeInstructor(): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        return $instructor;
    }

    /** An Available recording whose object really exists on the faked disk, owned by a completed booking. */
    private function storedRecording(User $student, User $instructor): Recording
    {
        $path = 'recordings/2026/08/lesson-'.Str::random(8).'.mp4';
        Storage::disk('local')->put($path, self::CONTENT);

        $booking = Booking::factory()->completed()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
        ]);
        $this->deliveredLesson($booking);

        return Recording::factory()->available()->create([
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'teacher_id' => $instructor->id,
            'storage_driver' => FilesystemRecordingStorage::KEY,
            'storage_path' => $path,
            'size_bytes' => strlen(self::CONTENT),
            'mime_type' => 'video/mp4',
        ]);
    }

    /** The canonical "delivered" fact: a Lesson with a finalized Completed outcome. */
    private function deliveredLesson(Booking $booking): Lesson
    {
        return Lesson::factory()->completed()->withOutcome(LessonOutcome::Completed)->create([
            'booking_id' => $booking->id,
            'student_id' => $booking->student_id,
            'instructor_id' => $booking->instructor_id,
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
        ]);
    }

    // ── Who may watch ─────────────────────────────────────────────────

    public function test_the_recordings_own_student_can_open_the_watch_page_and_the_stream(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $this->actingAs($student)
            ->get(route('dashboard.recordings.watch', $recording))
            ->assertOk()
            ->assertSee(route('dashboard.recordings.stream', $recording), false)
            ->assertSee($recording->booking->reference);

        $response = $this->actingAs($student)->get(route('dashboard.recordings.stream', $recording));

        $response->assertOk()
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Disposition', 'inline; filename="lesson-'.$recording->booking->reference.'.mp4"')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame(self::CONTENT, $response->streamedContent());
    }

    public function test_an_unrelated_student_is_refused_on_both_routes(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());
        $other = $this->activeStudent();

        $this->actingAs($other)->get(route('dashboard.recordings.watch', $recording))->assertForbidden();
        $this->actingAs($other)->get(route('dashboard.recordings.stream', $recording))->assertForbidden();
    }

    /** No SRS grant exists for the instructor, so none is implemented. */
    public function test_the_lessons_own_instructor_is_refused(): void
    {
        $instructor = $this->activeInstructor();
        $recording = $this->storedRecording($this->activeStudent(), $instructor);

        $this->actingAs($instructor)->get(route('dashboard.recordings.watch', $recording))->assertForbidden();
        $this->actingAs($instructor)->get(route('dashboard.recordings.stream', $recording))->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $this->get(route('dashboard.recordings.watch', $recording))->assertRedirect(route('auth.login'));
        $this->get(route('dashboard.recordings.stream', $recording))->assertRedirect(route('auth.login'));
    }

    /**
     * IDOR: a student who knows another recording's id — or guesses one —
     * gets nothing, because the grant is the canonical row's student_id
     * against the authenticated session, never the id in the URL.
     */
    public function test_a_student_cannot_reach_another_students_recording_by_changing_the_id(): void
    {
        $recordingA = $this->storedRecording($this->activeStudent(), $this->activeInstructor());
        $studentB = $this->activeStudent();
        $this->storedRecording($studentB, $this->activeInstructor());

        $this->actingAs($studentB)->get(route('dashboard.recordings.stream', $recordingA))->assertForbidden();
        $this->actingAs($studentB)->get(route('dashboard.recordings.watch', $recordingA))->assertForbidden();
    }

    public function test_a_guessed_recording_id_that_does_not_exist_is_not_found(): void
    {
        $student = $this->activeStudent();

        $this->actingAs($student)
            ->get(route('dashboard.recordings.watch', (string) Str::uuid()))
            ->assertNotFound();
    }

    /** A recording whose student matches but whose booking belongs to someone else can never arise from the pipeline, but the policy must still key on the row. */
    public function test_ownership_is_read_from_the_recording_row_not_from_client_input(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());
        $other = $this->activeStudent();

        // Query-string or body identifiers change nothing.
        $this->actingAs($other)
            ->get(route('dashboard.recordings.stream', $recording).'?student_id='.$student->id)
            ->assertForbidden();
    }

    // ── The lesson must have been DELIVERED ───────────────────────────

    /**
     * An Available recording is not enough: the canonical lifecycle must
     * say the session happened. Upcoming, live, cancelled, no-show and
     * disputed lessons — and a booking with no lesson at all — expose
     * nothing, whatever the recording row says.
     */
    public function test_a_recording_is_refused_unless_the_lesson_has_a_finalized_completed_outcome(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());
        $lesson = $recording->booking->lesson;

        foreach ([
            'pending outcome' => ['outcome' => LessonOutcome::Pending, 'outcome_finalized_at' => null],
            'cancelled' => ['outcome' => LessonOutcome::Cancelled, 'outcome_finalized_at' => now()],
            'student no-show' => ['outcome' => LessonOutcome::StudentNoShow, 'outcome_finalized_at' => now()],
            'completed but not finalized' => ['outcome' => LessonOutcome::Completed, 'outcome_finalized_at' => null],
        ] as $case => $attributes) {
            $lesson->forceFill($attributes)->save();

            $this->actingAs($student)->get(route('dashboard.recordings.stream', $recording))->assertForbidden();
            $this->assertFalse(Gate::forUser($student)->allows('watch', $recording->fresh()), $case);
        }

        $lesson->forceFill(['outcome' => LessonOutcome::Completed, 'outcome_finalized_at' => now()])->save();
        $this->actingAs($student)->get(route('dashboard.recordings.stream', $recording))->assertOk();

        $lesson->delete();
        $this->actingAs($student)->get(route('dashboard.recordings.stream', $recording))->assertForbidden();
    }

    // ── Policy switches ───────────────────────────────────────────────

    public function test_playback_is_refused_while_the_student_playback_setting_is_off(): void
    {
        $this->setPlayback(false);

        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $this->actingAs($student)->get(route('dashboard.recordings.watch', $recording))->assertForbidden();
        $this->actingAs($student)->get(route('dashboard.recordings.stream', $recording))->assertForbidden();
    }

    public function test_playback_is_refused_while_the_platform_recording_feature_is_off(): void
    {
        $this->setPlayback(true, featureFlag: false);

        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $this->actingAs($student)->get(route('dashboard.recordings.stream', $recording))->assertForbidden();
    }

    /** Implementation and activation are separate decisions: the setting is introduced OFF. */
    public function test_the_student_playback_setting_ships_off(): void
    {
        $migration = (string) file_get_contents(database_path('settings/2026_11_17_100000_add_recording_student_playback_setting.php'));

        $this->assertStringContainsString("add('meeting.recording_student_playback_enabled', false)", $migration);
    }

    /** The same strict lifecycle guard as the meeting join link: a suspended student loses playback too. */
    public function test_a_suspended_student_is_refused_their_own_recording(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $student->profile()->update(['student_status' => StudentStatus::Suspended]);

        // The Gate itself refuses (the resolver's lifecycle guard) …
        $this->assertFalse(Gate::forUser($student->fresh())->allows('watch', $recording));

        // … and the portal middleware turns the suspended student away
        // before the controller runs, so no bytes are served either way.
        $response = $this->actingAs($student->fresh())->get(route('dashboard.recordings.stream', $recording));
        $this->assertContains($response->status(), [302, 403]);
    }

    public function test_a_withheld_recording_is_refused_and_restored_access_works_again(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $recording->forceFill(['student_access_revoked_at' => now()])->save();

        $this->actingAs($student)->get(route('dashboard.recordings.stream', $recording))->assertForbidden();

        $recording->forceFill(['student_access_revoked_at' => null])->save();

        $this->actingAs($student)->get(route('dashboard.recordings.stream', $recording))->assertOk();
    }

    public function test_a_recording_still_processing_or_failed_or_expired_is_refused(): void
    {
        $student = $this->activeStudent();

        foreach ([RecordingStatus::Pending, RecordingStatus::Transferring, RecordingStatus::Stored, RecordingStatus::Failed] as $status) {
            $recording = $this->storedRecording($student, $this->activeInstructor());
            $recording->forceFill(['status' => $status])->save();

            $this->actingAs($student)
                ->get(route('dashboard.recordings.stream', $recording))
                ->assertForbidden();
        }

        $expired = $this->storedRecording($student, $this->activeInstructor());
        $expired->forceFill(['status' => RecordingStatus::Expired, 'storage_path' => null])->save();

        $this->actingAs($student)->get(route('dashboard.recordings.watch', $expired))->assertForbidden();
    }

    /** A permitted administrator may also watch — the same route, the same policy. */
    public function test_a_permitted_admin_can_watch_but_an_unpermitted_one_cannot(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actingAs($admin)->get(route('dashboard.recordings.stream', $recording))->assertForbidden();

        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'View:Recording', 'guard_name' => 'web']));
        $this->actingAs($admin)->get(route('dashboard.recordings.stream', $recording))->assertOk();
    }

    // ── Flag matrix: acquisition switches never hide existing recordings ──

    /**
     * meeting.recording_enabled ("record sessions by default") and the
     * per-provider recording toggles decide whether NEW recordings are
     * made. Turning them off must not make recordings that already
     * exist vanish from the students they were made for.
     */
    public function test_existing_playback_does_not_depend_on_the_acquisition_switches(): void
    {
        $meeting = app(MeetingSettings::class);
        $meeting->recording_enabled = false;
        $meeting->google_meet_recording_enabled = false;
        $meeting->zoom_recording_enabled = false;
        $meeting->save();

        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $this->actingAs($student)->get(route('dashboard.recordings.stream', $recording))->assertOk();
    }

    /** The full matrix, in one place: only the playback switch and the platform capability matter. */
    public function test_the_playback_flag_matrix(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $cases = [
            // [features.recording_enabled, meeting.recording_enabled, google_meet_recording_enabled, playback switch] => allowed
            [true, true, true, true, true],
            [true, false, false, true, true],
            [true, true, true, false, false],
            [false, true, true, true, false],
            [false, false, false, false, false],
        ];

        foreach ($cases as [$feature, $acquisition, $provider, $playback, $allowed]) {
            $features = app(FeatureSettings::class);
            $features->recording_enabled = $feature;
            $features->save();

            $meeting = app(MeetingSettings::class);
            $meeting->recording_enabled = $acquisition;
            $meeting->google_meet_recording_enabled = $provider;
            $meeting->recording_student_playback_enabled = $playback;
            $meeting->save();

            $status = $this->actingAs($student)->get(route('dashboard.recordings.stream', $recording))->getStatusCode();

            $this->assertSame($allowed ? 200 : 403, $status, sprintf('feature=%d acquisition=%d provider=%d playback=%d', $feature, $acquisition, $provider, $playback));
        }
    }

    // ── The locator can only come from the recording row ─────────────

    /**
     * Nothing a request carries can choose the object: file ids, paths,
     * provider references and folder ids in the query are ignored and
     * the viewer receives their own recording's bytes regardless.
     */
    public function test_request_parameters_can_never_select_the_underlying_object(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $other = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $query = http_build_query([
            'file_id' => $other->storage_path,
            'drive_file_id' => $other->storage_path,
            'storage_path' => $other->storage_path,
            'provider_reference' => $other->provider_reference,
            'folder_id' => 'root',
            'recording' => $other->getKey(),
        ]);

        $response = $this->actingAs($student)->get(route('dashboard.recordings.stream', $recording).'?'.$query);

        $response->assertOk();
        $this->assertSame(self::CONTENT, $response->streamedContent());
    }

    // ── Seeking ───────────────────────────────────────────────────────

    public function test_a_range_request_is_answered_with_exactly_that_window(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $response = $this->actingAs($student)
            ->withHeaders(['Range' => 'bytes=10-19'])
            ->get(route('dashboard.recordings.stream', $recording));

        $response->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 10-19/'.strlen(self::CONTENT))
            ->assertHeader('Content-Length', '10')
            ->assertHeader('Accept-Ranges', 'bytes');

        $this->assertSame('abcdefghij', $response->streamedContent());
    }

    public function test_an_open_ended_range_runs_to_the_end_of_the_object(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $response = $this->actingAs($student)
            ->withHeaders(['Range' => 'bytes=30-'])
            ->get(route('dashboard.recordings.stream', $recording));

        $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 30-35/36');
        $this->assertSame('uvwxyz', $response->streamedContent());
    }

    /**
     * A browser's first request is `bytes=0-`, the whole object. The
     * playback window is capped so one PHP worker serves a bounded
     * transfer and the player asks again — never a whole lesson's
     * worth of trickled bytes on one request.
     */
    public function test_a_playback_window_is_bounded_and_the_player_can_continue_from_where_it_ended(): void
    {
        config(['recordings.playback.max_range_bytes' => 10]);

        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $first = $this->actingAs($student)
            ->withHeaders(['Range' => 'bytes=0-'])
            ->get(route('dashboard.recordings.stream', $recording));

        $first->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-9/36')
            ->assertHeader('Content-Length', '10');
        $this->assertSame('0123456789', $first->streamedContent());

        $next = $this->actingAs($student)
            ->withHeaders(['Range' => 'bytes=10-'])
            ->get(route('dashboard.recordings.stream', $recording));

        $next->assertStatus(206)->assertHeader('Content-Range', 'bytes 10-19/36');
        $this->assertSame('abcdefghij', $next->streamedContent());

        // A window already inside the cap is served exactly.
        $small = $this->actingAs($student)
            ->withHeaders(['Range' => 'bytes=30-'])
            ->get(route('dashboard.recordings.stream', $recording));

        $small->assertStatus(206)->assertHeader('Content-Range', 'bytes 30-35/36');
        $this->assertSame('uvwxyz', $small->streamedContent());
    }

    /** The cap is a playback concern; an administrator's attachment is always the whole file. */
    public function test_the_admin_download_is_never_windowed(): void
    {
        config(['recordings.playback.max_range_bytes' => 10]);

        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'View:Recording', 'guard_name' => 'web']));

        $response = $this->actingAs($admin)
            ->withHeaders(['Range' => 'bytes=0-'])
            ->get(route('admin.recordings.download', $recording));

        $response->assertOk()
            ->assertHeader('Accept-Ranges', 'none')
            ->assertHeader('Content-Length', '36');
        $this->assertSame(self::CONTENT, $response->streamedContent());
    }

    /**
     * A row that says Available whose object has gone must fail BEFORE
     * headers are sent — a clean 503, never a truncated 200 — and must
     * leave an operator-facing log entry rather than a silent gap.
     */
    public function test_a_missing_storage_object_fails_cleanly_before_any_bytes(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        Storage::disk('local')->delete($recording->storage_path);

        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) use ($recording): bool {
            return str_contains($message, 'could not be opened')
                && $context['recording_id'] === $recording->getKey()
                && ! isset($context['storage_path']);
        });

        $this->actingAs($student)
            ->withHeaders(['Range' => 'bytes=0-'])
            ->get(route('dashboard.recordings.stream', $recording))
            ->assertStatus(503);
    }

    public function test_an_unsatisfiable_range_is_416_with_the_object_size(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $this->actingAs($student)
            ->withHeaders(['Range' => 'bytes=500-600'])
            ->get(route('dashboard.recordings.stream', $recording))
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */36');
    }

    /** A Range request is still an authorization check — every seek is re-authorized. */
    public function test_a_range_request_from_the_wrong_student_is_refused(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $this->actingAs($this->activeStudent())
            ->withHeaders(['Range' => 'bytes=0-9'])
            ->get(route('dashboard.recordings.stream', $recording))
            ->assertForbidden();
    }

    public function test_a_head_request_returns_headers_without_a_body(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $this->actingAs($student)
            ->head(route('dashboard.recordings.stream', $recording))
            ->assertOk()
            ->assertHeader('Content-Length', (string) strlen(self::CONTENT))
            ->assertHeader('Accept-Ranges', 'bytes');
    }

    // ── What is never exposed ─────────────────────────────────────────

    public function test_neither_the_page_nor_the_stream_exposes_the_storage_locator_or_a_backend_url(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $page = $this->actingAs($student)->get(route('dashboard.recordings.watch', $recording));
        $html = $page->getContent();

        foreach ([$recording->storage_path, $recording->provider_reference, 'drive.google.com', 'googleapis.com', 'storage_driver', 'recordings.download'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }

        // The PLAYER identifies the viewer by id only — never by email or
        // phone. (The surrounding account chrome shows the logged-in
        // user their own email, as on every portal page; the watermark
        // block is what must stay minimal.)
        $playerStart = strpos($html, 'recordingPlayer(');
        $playerEnd = strpos($html, 'data-recording-watermark', (int) $playerStart);
        $this->assertNotFalse($playerStart);
        $player = substr($html, (int) $playerStart, (int) $playerEnd - (int) $playerStart);
        $this->assertStringNotContainsString($student->email, $player);
        $this->assertStringNotContainsString($student->name, $player);
        $this->assertStringNotContainsString('Student #', $player, 'no database id on the video');
        $this->assertStringContainsString($recording->booking->reference, $player);

        $stream = $this->actingAs($student)->get(route('dashboard.recordings.stream', $recording));
        $headers = json_encode($stream->headers->all());

        $this->assertStringNotContainsString($recording->storage_path, $headers);
        $this->assertStringNotContainsString('drive.google.com', $headers);
        $this->assertStringNotContainsString('googleapis.com', $headers);
    }

    public function test_the_player_offers_no_download_control(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $this->actingAs($student)
            ->get(route('dashboard.recordings.watch', $recording))
            ->assertSee('nodownload', false)
            ->assertSee('data-recording-watermark', false);
    }

    // ── Audit ─────────────────────────────────────────────────────────

    public function test_opening_the_player_is_audited_and_a_refusal_is_audited(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $this->actingAs($student)->get(route('dashboard.recordings.watch', $recording))->assertOk();

        $opened = Activity::query()
            ->where('log_name', 'recordings')
            ->where('event', 'recording_playback_opened')
            ->where('subject_id', $recording->getKey())
            ->first();

        $this->assertNotNull($opened);
        $this->assertSame($student->id, (int) $opened->causer_id);

        $other = $this->activeStudent();
        $this->actingAs($other)->get(route('dashboard.recordings.stream', $recording))->assertForbidden();

        $denied = Activity::query()
            ->where('log_name', 'recordings')
            ->where('event', 'recording_access_denied')
            ->where('subject_id', $recording->getKey())
            ->first();

        $this->assertNotNull($denied);
        $this->assertSame($other->id, (int) $denied->causer_id);
        $this->assertSame('watch', $denied->properties['ability'] ?? null);

        // The audit row must not carry a locator either.
        $this->assertStringNotContainsString($recording->storage_path, json_encode($opened->properties));
    }

    /**
     * An authenticated user hammering one recording id they may not
     * watch must not be able to fill the audit table: the first refusal
     * in a window is audited, repeats go to the application log.
     */
    public function test_repeated_refusals_are_audited_once_per_window(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());
        $other = $this->activeStudent();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($other)->get(route('dashboard.recordings.stream', $recording))->assertForbidden();
        }

        $this->assertSame(1, Activity::query()->where('event', 'recording_access_denied')->count());

        // A different recording, or a different viewer, is its own signal.
        $this->actingAs($other)->get(route('dashboard.recordings.watch', $recording))->assertForbidden();
        $this->assertSame(1, Activity::query()->where('event', 'recording_access_denied')->count(), 'same viewer, same recording, same ability');

        $another = $this->activeStudent();
        $this->actingAs($another)->get(route('dashboard.recordings.stream', $recording))->assertForbidden();
        $this->assertSame(2, Activity::query()->where('event', 'recording_access_denied')->count());
    }

    /** Streaming does not write an audit row per request — a seeking player would flood the log. */
    public function test_range_requests_are_not_individually_audited(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        foreach (['bytes=0-9', 'bytes=10-19', 'bytes=20-'] as $range) {
            $this->actingAs($student)
                ->withHeaders(['Range' => $range])
                ->get(route('dashboard.recordings.stream', $recording))
                ->assertStatus(206);
        }

        $this->assertSame(0, Activity::query()->where('log_name', 'recordings')->where('event', 'recording_playback_opened')->count());
    }
}
