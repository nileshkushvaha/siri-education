<?php

declare(strict_types=1);

namespace App\Queue\Services;

use App\Models\User;
use App\Queue\DTOs\FailedJobRetryResult;
use App\Queue\Support\FailedJobPayloadSummary;
use App\Services\AuditTrailService;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * SRS-26-2: the sole authority for retrying a
 * failed queue job from the admin UI. Mirrors Laravel's own
 * `queue:retry` command (Illuminate\Queue\Console\RetryCommand) —
 * resets `attempts`, refreshes `retryUntil` for jobs that define it,
 * and re-queues the RAW payload string via the queue manager's
 * `pushRaw()` — the job is never unserialized-and-executed, never
 * invoked via `handle()`, and never touched via shell/artisan.
 *
 * Concurrency: the failed_jobs row is locked (`lockForUpdate()`) inside
 * a DB transaction for the duration of the requeue + forget + audit —
 * verified empirically (see FailedJobRetryConcurrencyTest) to make the
 * database queue's retry atomic, since the `jobs` table insert
 * (DatabaseQueue::pushRaw()) and the `failed_jobs` delete share the
 * same default database connection as this transaction. A concurrent
 * second attempt on the same UUID finds the row already gone (deleted
 * by the winner) and returns NotFound — never a duplicate enqueue.
 *
 * External-queue honesty: for a non-database queue connection (Redis,
 * SQS, etc.) this same transaction still protects the `failed_jobs`
 * row/audit atomically, but the actual enqueue call is a network
 * operation outside this app's database — if the process crashes
 * between a successful remote enqueue and this transaction committing
 * the delete+audit, that is an at-least-once (not exactly-once)
 * window, identical to the ambiguity Laravel's own `queue:retry`
 * command carries for those drivers. Not claimed otherwise.
 */
final class FailedJobRetryService
{
    private const string RETRY_ABILITY = 'queue_monitor.retry_failed_jobs';

    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    /** @throws AuthorizationException */
    public function retry(User $actor, string $failedJobUuid): FailedJobRetryResult
    {
        Gate::forUser($actor)->authorize(self::RETRY_ABILITY);

        return DB::transaction(function () use ($actor, $failedJobUuid): FailedJobRetryResult {
            $row = DB::table('failed_jobs')->where('uuid', $failedJobUuid)->lockForUpdate()->first();

            if ($row === null) {
                return FailedJobRetryResult::notFound($failedJobUuid);
            }

            $summary = FailedJobPayloadSummary::fromRawPayload($row->payload);

            if (! $this->isSupportedConnection($row->connection)) {
                $this->auditFailure($actor, $row, $summary, 'unsupported_connection');

                return FailedJobRetryResult::unsupportedConnection($failedJobUuid, $summary->displayName);
            }

            try {
                $payload = $this->prepareRetryPayload($row->payload);

                app('queue')->connection($row->connection)->pushRaw($payload, $row->queue);
            } catch (Throwable $e) {
                report($e);
                $this->auditFailure($actor, $row, $summary, $summary->isMalformed ? 'malformed_payload' : 'enqueue_failed');

                return FailedJobRetryResult::enqueueFailed($failedJobUuid, $summary->displayName);
            }

            DB::table('failed_jobs')->where('uuid', $failedJobUuid)->delete();

            // If this audit write throws, DB::transaction() rolls back
            // everything above (the pushRaw insert and the delete both
            // share this same connection) — a retry is never silently
            // unaudited.
            $this->auditSuccess($actor, $row, $summary);

            return FailedJobRetryResult::retried($failedJobUuid, $summary->displayName);
        });
    }

    private function isSupportedConnection(string $connection): bool
    {
        $config = config("queue.connections.{$connection}");

        if (! is_array($config)) {
            return false;
        }

        return ! in_array($config['driver'] ?? null, ['sync', 'null'], true);
    }

    /** Mirrors RetryCommand::resetAttempts()/refreshRetryUntil() exactly. */
    private function prepareRetryPayload(string $rawPayload): string
    {
        $payload = json_decode($rawPayload, true);

        if (! is_array($payload)) {
            throw new RuntimeException('Malformed failed-job payload.');
        }

        if (isset($payload['attempts'])) {
            $payload['attempts'] = 0;
        }

        if (isset($payload['data']['command'])) {
            $this->refreshRetryUntil($payload);
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $payload */
    private function refreshRetryUntil(array &$payload): void
    {
        try {
            $instance = $this->instanceFromCommand((string) $payload['data']['command']);
        } catch (Throwable) {
            // Refreshing retryUntil is a best-effort canonical-behavior
            // step, not a precondition for a safe retry — an encrypted
            // or otherwise unreadable command still re-queues correctly
            // via its untouched raw payload string.
            return;
        }

        if (is_object($instance) && method_exists($instance, 'retryUntil')) {
            $retryUntil = $instance->retryUntil();
            $payload['retryUntil'] = $retryUntil instanceof DateTimeInterface ? $retryUntil->getTimestamp() : $retryUntil;
        }
    }

    private function instanceFromCommand(string $command): mixed
    {
        if (str_starts_with($command, 'O:')) {
            return unserialize($command);
        }

        if (app()->bound(Encrypter::class)) {
            return unserialize(app(Encrypter::class)->decrypt($command));
        }

        throw new RuntimeException('Unable to extract job payload.');
    }

    private function auditSuccess(User $actor, stdClass $row, FailedJobPayloadSummary $summary): void
    {
        $this->audit->logUser(
            $actor,
            'queue',
            'failed_job_retried',
            sprintf('Failed job %s re-queued.', $this->shortReference($row->uuid)),
            null,
            [
                'failed_job_uuid' => $row->uuid,
                'display_name' => $summary->displayName,
                'connection' => $row->connection,
                'queue' => $row->queue,
                'failed_at' => (string) $row->failed_at,
                'retry_requested_at' => now()->toIso8601String(),
                'result' => 'retried',
            ],
        );
    }

    private function auditFailure(User $actor, stdClass $row, FailedJobPayloadSummary $summary, string $failureCategory): void
    {
        $this->audit->logUser(
            $actor,
            'queue',
            'failed_job_retry_failed',
            sprintf('Failed job %s retry attempt did not succeed.', $this->shortReference($row->uuid)),
            null,
            [
                'failed_job_uuid' => $row->uuid,
                'display_name' => $summary->displayName,
                'connection' => $row->connection,
                'queue' => $row->queue,
                'failed_at' => (string) $row->failed_at,
                'retry_requested_at' => now()->toIso8601String(),
                'result' => 'failed',
                'failure_category' => $failureCategory,
            ],
        );
    }

    private function shortReference(string $uuid): string
    {
        return substr($uuid, 0, 8);
    }
}
