<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Queue\Enums\FailedJobRetryOutcome;
use App\Queue\Services\FailedJobRetryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24N — GAP-034 (SRS-26-2): FailedJobRetryService mirrors
 * Laravel's own `queue:retry` canonical behavior (reset attempts,
 * refresh retryUntil, pushRaw — never unserialize-and-execute).
 */
final class FailedJobRetryServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'queue_monitor.retry_failed_jobs', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])->givePermissionTo('queue_monitor.retry_failed_jobs');

        $this->manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->manager->assignRole('manager');
    }

    // ── 1-6: authorized retry, one new queue row, payload/connection preserved ──

    public function test_authorized_admin_retries_a_valid_failed_job(): void
    {
        $uuid = $this->failJob(new RetryTestJob('hello'));

        $result = app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        $this->assertSame(FailedJobRetryOutcome::Retried, $result->outcome);
        $this->assertSame(RetryTestJob::class, $result->displayName);
    }

    public function test_one_new_queue_row_is_created(): void
    {
        $uuid = $this->failJob(new RetryTestJob('hello'));

        app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_failed_row_is_removed_only_after_enqueue_succeeds(): void
    {
        $uuid = $this->failJob(new RetryTestJob('hello'));

        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', $uuid)->count());

        app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        $this->assertSame(0, DB::table('failed_jobs')->where('uuid', $uuid)->count());
    }

    public function test_original_connection_and_queue_are_preserved(): void
    {
        $uuid = $this->failJob(new RetryTestJob('hello'), queue: 'high-priority');

        app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        $row = DB::table('jobs')->sole();
        $this->assertSame('high-priority', $row->queue);
    }

    public function test_payload_retry_metadata_matches_laravel_canonical_behavior(): void
    {
        $uuid = $this->failJob(new RetryTestJob('hello'));
        $originalPayload = json_decode(DB::table('failed_jobs')->where('uuid', $uuid)->value('payload'), true);

        app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        $newRow = DB::table('jobs')->sole();
        $this->assertSame(0, $newRow->attempts);
        $newPayload = json_decode($newRow->payload, true);
        $this->assertSame($originalPayload['uuid'], $newPayload['uuid']);
        $this->assertSame($originalPayload['displayName'], $newPayload['displayName']);
    }

    public function test_job_is_not_executed_synchronously(): void
    {
        $uuid = $this->failJob(new RetryTestJob('hello'));

        app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        // The job is only PUSHED — nothing in this process ever calls
        // handle(), so the side-effect file the job would write never appears.
        $this->assertFileDoesNotExist(RetryTestJob::markerPath());
    }

    // ── 7-8: authorization ─────────────────────────────────────────────

    public function test_unauthorized_user_cannot_retry(): void
    {
        $uuid = $this->failJob(new RetryTestJob('hello'));
        $plainUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->expectException(AuthorizationException::class);

        app(FailedJobRetryService::class)->retry($plainUser, $uuid);
    }

    public function test_direct_service_call_enforces_authorization(): void
    {
        // No Filament/HTTP context at all — the service itself must gate.
        $uuid = $this->failJob(new RetryTestJob('hello'));
        $plainUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        try {
            app(FailedJobRetryService::class)->retry($plainUser, $uuid);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    // ── 9-11: missing/malformed ────────────────────────────────────────

    public function test_missing_job_returns_safe_not_found_result(): void
    {
        $result = app(FailedJobRetryService::class)->retry($this->manager, (string) Str::uuid());

        $this->assertSame(FailedJobRetryOutcome::NotFound, $result->outcome);
    }

    public function test_already_retried_job_returns_safe_not_found_on_second_call(): void
    {
        $uuid = $this->failJob(new RetryTestJob('hello'));
        app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        $result = app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        $this->assertSame(FailedJobRetryOutcome::NotFound, $result->outcome);
    }

    public function test_malformed_payload_does_not_crash_and_is_not_removed(): void
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => 'not valid json {{{',
            'exception' => 'Some exception',
            'failed_at' => now(),
        ]);

        $result = app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        $this->assertSame(FailedJobRetryOutcome::EnqueueFailed, $result->outcome);
        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', $uuid)->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    // ── 12: unsupported connection ─────────────────────────────────────

    public function test_unsupported_connection_is_rejected(): void
    {
        $uuid = $this->failJob(new RetryTestJob('hello'), connection: 'sync');

        $result = app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        $this->assertSame(FailedJobRetryOutcome::UnsupportedConnection, $result->outcome);
        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', $uuid)->count());
    }

    public function test_unknown_connection_is_rejected(): void
    {
        $uuid = $this->failJob(new RetryTestJob('hello'), connection: 'totally-made-up');

        $result = app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        $this->assertSame(FailedJobRetryOutcome::UnsupportedConnection, $result->outcome);
    }

    // ── 15-16: audit ────────────────────────────────────────────────────

    public function test_successful_retry_creates_safe_audit_evidence(): void
    {
        $uuid = $this->failJob(new RetryTestJob('secret-argument-value'));

        app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        $activity = Activity::query()->where('log_name', 'queue')->where('event', 'failed_job_retried')->sole();
        $this->assertSame($uuid, $activity->properties['failed_job_uuid']);
        $this->assertSame(RetryTestJob::class, $activity->properties['display_name']);
        $this->assertSame('database', $activity->properties['connection']);
        $this->assertSame('retried', $activity->properties['result']);
    }

    public function test_audit_contains_no_raw_payload_or_exception(): void
    {
        $uuid = $this->failJob(new RetryTestJob('super-secret-argument-xyz'), exception: 'RuntimeException: leaked-secret-token-abc123');

        app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        $activity = Activity::query()->where('log_name', 'queue')->where('event', 'failed_job_retried')->sole();
        $json = $activity->properties->toJson();
        $this->assertStringNotContainsString('super-secret-argument-xyz', $json);
        $this->assertStringNotContainsString('leaked-secret-token-abc123', $json);
    }

    public function test_failed_retry_writes_a_safe_failure_audit(): void
    {
        $uuid = $this->failJob(new RetryTestJob('hello'), connection: 'sync');

        app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        $activity = Activity::query()->where('log_name', 'queue')->where('event', 'failed_job_retry_failed')->sole();
        $this->assertSame('unsupported_connection', $activity->properties['failure_category']);
    }

    // ── 23: encrypted payload ───────────────────────────────────────────

    public function test_encrypted_payload_follows_laravel_retry_behavior(): void
    {
        $uuid = $this->failJob(new EncryptedRetryTestJob('hello'));

        $result = app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        $this->assertSame(FailedJobRetryOutcome::Retried, $result->outcome);
        $newRow = DB::table('jobs')->sole();
        $newPayload = json_decode($newRow->payload, true);
        // Still encrypted — never decrypted-and-re-serialized-plain.
        $this->assertFalse(str_starts_with($newPayload['data']['command'], 'O:'));
        app(Encrypter::class)->decrypt($newPayload['data']['command']); // does not throw
        $this->assertTrue(true);
    }

    // ── 26: no external provider/job execution ──────────────────────────

    public function test_no_job_execution_or_external_call_occurs(): void
    {
        $uuid = $this->failJob(new RetryTestJob('hello'));

        app(FailedJobRetryService::class)->retry($this->manager, $uuid);

        $this->assertFileDoesNotExist(RetryTestJob::markerPath());
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    /**
     * Dispatches a real job through the (always-safe, never-executing)
     * database queue to obtain an authentic Laravel payload, then
     * records it as a failed job under the REQUESTED connection —
     * decoupled from how the payload was generated, since actually
     * dispatching through 'sync' would execute the job inline, and a
     * genuinely unknown connection name cannot be dispatched through at
     * all. This mirrors a real-world failed row whose recorded
     * connection is no longer configured/supported.
     */
    private function failJob(object $job, string $queue = 'default', string $connection = 'database', string $exception = "RuntimeException: boom\n#0 test"): string
    {
        dispatch($job)->onConnection('database')->onQueue($queue);

        $row = DB::table('jobs')->latest('id')->first();
        $payload = json_decode($row->payload, true);
        $uuid = $payload['uuid'];

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => $connection,
            'queue' => $queue,
            'payload' => $row->payload,
            'exception' => $exception,
            'failed_at' => now(),
        ]);

        DB::table('jobs')->where('id', $row->id)->delete();

        return $uuid;
    }
}

/** Test-only job — never actually executed in these tests (only pushed/popped as raw payload). */
final class RetryTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $arg) {}

    public function handle(): void
    {
        file_put_contents(self::markerPath(), 'executed');
    }

    public static function markerPath(): string
    {
        return sys_get_temp_dir().'/retry_test_job_executed_'.md5(self::class);
    }
}

final class EncryptedRetryTestJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $arg) {}

    public function handle(): void {}
}
