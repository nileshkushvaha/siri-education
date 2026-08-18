<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Contracts\AiRunRepositoryInterface;
use App\Ai\DTOs\AiTaskRequest;
use App\Ai\DTOs\AiUsage;
use App\Ai\Enums\AiFailureCode;
use App\Ai\Enums\AiRunStatus;
use App\Models\AiRun;

/**
 * Writes the ai_runs lifecycle. The single writer of that table, so
 * "no prompt or response is ever persisted" is enforceable by reading
 * one class: nothing here accepts prompt text, response text or
 * variables, and the DTOs it takes carry only counters and codes.
 *
 * A run row is created BEFORE the provider call, not after. A process
 * killed mid-call therefore leaves a visible Running row rather than no
 * evidence at all — the difference between an operator seeing a stuck
 * execution and seeing nothing happen.
 */
final class AiRunRecorder
{
    public function __construct(
        private readonly AiRunRepositoryInterface $runs,
        private readonly AiCostEstimator $costs,
    ) {}

    public function start(AiTaskRequest $request, string $provider, string $model, string $promptKey, string $promptVersion): AiRun
    {
        return $this->runs->create([
            'feature_key' => $request->feature->value,
            'provider' => $provider,
            'model' => $model,
            'prompt_key' => $promptKey,
            'prompt_version' => $promptVersion,
            'subject_type' => $request->subjectType,
            'subject_id' => $request->subjectId,
            'requested_by' => $request->requestedBy,
            'status' => AiRunStatus::Running->value,
            'cost_currency' => $this->costs->currency(),
        ]);
    }

    /**
     * A refusal that never reached the provider — flag off, no
     * credential, over budget. Recorded rather than dropped so a feature
     * that quietly does nothing is still visible to an operator.
     */
    public function blocked(AiTaskRequest $request, string $provider, AiFailureCode $code): AiRun
    {
        return $this->runs->create([
            'feature_key' => $request->feature->value,
            'provider' => $provider,
            'prompt_key' => $request->promptKey,
            'prompt_version' => $request->promptVersion,
            'subject_type' => $request->subjectType,
            'subject_id' => $request->subjectId,
            'requested_by' => $request->requestedBy,
            'status' => AiRunStatus::Blocked->value,
            'failure_code' => $code->value,
            'cost_currency' => $this->costs->currency(),
            'completed_at' => now(),
        ]);
    }

    public function succeeded(AiRun $run, AiUsage $usage, int $latencyMs): AiRun
    {
        return $this->finish($run, AiRunStatus::Succeeded, $usage, $latencyMs, null);
    }

    /**
     * A response arrived but failed schema validation. Usage is still
     * recorded: the provider billed for those tokens whether or not the
     * answer was usable.
     */
    public function rejected(AiRun $run, AiUsage $usage, int $latencyMs, AiFailureCode $code): AiRun
    {
        return $this->finish($run, AiRunStatus::Rejected, $usage, $latencyMs, $code);
    }

    public function failed(AiRun $run, AiFailureCode $code, int $latencyMs, ?AiUsage $usage = null): AiRun
    {
        return $this->finish($run, AiRunStatus::Failed, $usage ?? AiUsage::none(), $latencyMs, $code);
    }

    private function finish(AiRun $run, AiRunStatus $status, AiUsage $usage, int $latencyMs, ?AiFailureCode $code): AiRun
    {
        return $this->runs->update($run, [
            'status' => $status->value,
            'failure_code' => $code?->value,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'estimated_cost' => $this->costs->estimate((string) $run->model, $usage->inputTokens, $usage->outputTokens),
            'latency_ms' => $latencyMs,
            'provider_request_id' => $usage->providerRequestId,
            'completed_at' => now(),
        ]);
    }
}
