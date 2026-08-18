<?php

declare(strict_types=1);

namespace App\Ai\Jobs;

use App\Ai\Contracts\AiExecutionServiceInterface;
use App\Ai\Contracts\AiFeatureRegistryInterface;
use App\Ai\Contracts\AiTaskInputResolverInterface;
use App\Ai\Contracts\AiTaskResultHandlerInterface;
use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\DTOs\AiTaskRequest;
use App\Ai\DTOs\AiTaskResult;
use App\Ai\DTOs\AiUsage;
use App\Ai\Enums\AiFailureCode;
use App\Ai\Enums\AiRunStatus;
use App\Ai\Exceptions\AiException;
use App\Ai\Services\AiLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * THE reusable AI job. Every future AI feature dispatches this — none
 * of them writes its own job class, so retry policy, timeout, queue
 * isolation and content discipline are decided once.
 *
 * AI work is asynchronous by default. A provider call is a
 * multi-second network round trip against a system with rate limits and
 * outages; doing it inside a Livewire round trip or a controller would
 * make a page load hostage to a third party.
 *
 * CARRIES NO CONTENT. The payload is an AiTaskDescriptor — identifiers
 * only — and the input resolver fetches the actual text at execution
 * time. A queue payload is a database row that outlives the request (and
 * survives far longer in failed_jobs), so putting a student's homework
 * in one would create an uncontrolled second copy of it.
 *
 * Dedicated `ai` connection and queue: retry_after there is set above
 * this timeout, so a slow generation is never handed to a second worker
 * mid-flight, and a rate-limited backlog can never sit in front of
 * notification or payment work.
 */
final class ExecuteAiTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Comfortably above AiSettings::$request_timeout_seconds (default 30). */
    public int $timeout = 120;

    /** Small budget: transient provider faults recover fast, and everything else is not retried at all. */
    public int $tries = 3;

    /** Seconds between attempts — long enough for a rate-limit window to clear. */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(
        public readonly AiTaskDescriptor $descriptor,
    ) {
        $this->onConnection('ai');
        $this->onQueue('ai');
    }

    public function handle(AiExecutionServiceInterface $ai, AiLogger $log, AiFeatureRegistryInterface $registry): void
    {
        // The descriptor names the class that will read platform data.
        // It arrives from a queue payload, so it is checked against the
        // feature's registered allowlist before anything is resolved
        // from the container — otherwise the boundary deciding what may
        // reach a provider would be whatever a caller wrote down.
        if (! $this->isPermittedByRegistry($registry, $log)) {
            $this->deliver(new AiTaskResult(
                runId: '',
                status: AiRunStatus::Blocked,
                usage: AiUsage::none(),
                failureCode: AiFailureCode::FeatureNotPermitted,
            ), $log);

            return;
        }

        try {
            $variables = $this->resolver($registry)->resolve($this->descriptor);
        } catch (AiException $e) {
            // The subject vanished or became ineligible between dispatch
            // and execution. Not a provider failure and not retryable —
            // and nothing was spent. The waiting domain record is still
            // told, so it never sits Pending forever.
            $log->warning('AI task input could not be resolved', [
                'feature' => $this->descriptor->feature->value,
                'prompt_key' => $this->descriptor->promptKey,
                'failure_code' => $e->failureCode->value,
                'attempt' => $this->attempts(),
            ]);

            $this->deliver(new AiTaskResult(
                runId: '',
                status: AiRunStatus::Blocked,
                usage: AiUsage::none(),
                failureCode: $e->failureCode,
            ), $log);

            return;
        }

        $result = $ai->execute(AiTaskRequest::fromDescriptor($this->descriptor, $variables));

        if ($result->succeeded()) {
            $this->deliver($result, $log);

            return;
        }

        // Retry only what the failure code says is transient. Everything
        // else — bad credentials, a disabled flag, an exhausted budget,
        // a filtered request — is already recorded on the ai_runs row,
        // and retrying it would burn tokens to reach the same answer.
        if ($result->shouldRetry() && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[min($this->attempts(), count($this->backoff())) - 1] ?? 120);

            return;
        }

        $log->warning('AI task finished without a usable result', [
            'run_id' => $result->runId,
            'feature' => $this->descriptor->feature->value,
            'prompt_key' => $this->descriptor->promptKey,
            'status' => $result->status->value,
            'failure_code' => $result->failureCode?->value,
            'attempt' => $this->attempts(),
        ]);

        $this->deliver($result, $log);
    }

    /**
     * Verifies this descriptor against the feature's declared shape,
     * and records the denial where an operator will see it.
     */
    private function isPermittedByRegistry(AiFeatureRegistryInterface $registry, AiLogger $log): bool
    {
        $feature = $this->descriptor->feature;

        $permitted = $registry->has($feature)
            && $registry->get($feature)->allowsResolver($this->descriptor->inputResolver)
            && $registry->get($feature)->allowsHandler($this->descriptor->resultHandler)
            && $registry->get($feature)->allowsPrompt($this->descriptor->promptKey);

        if (! $permitted) {
            $log->warning('AI task refused by the feature registry', [
                'feature' => $feature->value,
                'prompt_key' => $this->descriptor->promptKey,
                'failure_code' => AiFailureCode::FeatureNotPermitted->value,
                'attempt' => $this->attempts(),
            ]);
        }

        return $permitted;
    }

    /**
     * Resolves the feature's ONE approved resolver. Type-checked as
     * well as allowlisted: the container will happily build any class,
     * and a resolver that is not a resolver would fail somewhere less
     * obvious.
     */
    private function resolver(AiFeatureRegistryInterface $registry): AiTaskInputResolverInterface
    {
        $resolver = app($registry->get($this->descriptor->feature)->inputResolver);

        if (! $resolver instanceof AiTaskInputResolverInterface) {
            throw new AiException('AI input resolvers must implement AiTaskInputResolverInterface.', AiFailureCode::FeatureNotPermitted);
        }

        return $resolver;
    }

    /**
     * Hands the terminal outcome to the owning domain, if one is
     * waiting. Runs for failures too — a domain record left Pending
     * forever because the provider was down is a worse outcome than one
     * marked Failed with a reason an admin can read.
     *
     * A handler that throws must not resurrect the queue job: the AI
     * work is already finished and paid for, and retrying it would spend
     * again to fix a bug on the domain side. The failure is logged
     * instead, and the record stays in whatever state it was in.
     */
    private function deliver(AiTaskResult $result, AiLogger $log): void
    {
        $handler = $this->descriptor->resultHandler;

        if ($handler === null) {
            return;
        }

        try {
            $resolved = app($handler);

            if (! $resolved instanceof AiTaskResultHandlerInterface) {
                throw new \InvalidArgumentException('AI result handlers must implement AiTaskResultHandlerInterface.');
            }

            $resolved->handle($this->descriptor, $result);
        } catch (\Throwable) {
            $log->warning('AI task result could not be delivered to its domain handler', [
                'run_id' => $result->runId,
                'feature' => $this->descriptor->feature->value,
                'status' => $result->status->value,
                'attempt' => $this->attempts(),
            ]);
        }
    }

    /** Groups retries and telemetry per feature without a bespoke job class per feature. */
    public function tags(): array
    {
        return ['ai', 'ai:'.$this->descriptor->feature->value];
    }
}
