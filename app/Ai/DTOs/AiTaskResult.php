<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

use App\Ai\Enums\AiFailureCode;
use App\Ai\Enums\AiRunStatus;

/**
 * The outcome of one execution. AiExecutionService does not throw for
 * provider or validation failures — it records the run and returns a
 * failed result, so a caller must make an explicit decision about a
 * missing answer rather than having an exception decide for it. An AI
 * feature being unavailable is a normal operating condition, never an
 * application error.
 *
 * $data is the VALIDATED structured payload; $text is free-form output.
 * Exactly one of them is populated on success, per the capability used.
 */
final readonly class AiTaskResult
{
    /** @param array<string, mixed>|null $data */
    public function __construct(
        public string $runId,
        public AiRunStatus $status,
        public AiUsage $usage,
        public int $latencyMs = 0,
        public ?string $text = null,
        public ?array $data = null,
        public ?AiFailureCode $failureCode = null,
        public ?AiModerationResult $moderation = null,
        public ?AiEmbeddingResult $embedding = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === AiRunStatus::Succeeded;
    }

    public function shouldRetry(): bool
    {
        return $this->failureCode?->isRetryable() ?? false;
    }
}
