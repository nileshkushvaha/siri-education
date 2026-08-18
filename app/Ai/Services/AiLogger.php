<?php

declare(strict_types=1);

namespace App\Ai\Services;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * The only logging path in the AI module, built as an ALLOWLIST rather
 * than a redactor.
 *
 * A denylist ("strip anything called prompt") fails the moment someone
 * adds a differently-named key. Here, context keys not on the list are
 * dropped outright, so the failure mode of a careless call site is a
 * missing field in a log line — never student content or a credential
 * in one.
 *
 * Permitted: feature, provider, model, prompt key/version, status,
 * failure code, latency, token counts, estimated cost, run id.
 * Structurally impossible: prompts, responses, subjects' content, API
 * keys.
 */
final class AiLogger
{
    /** @var list<string> */
    private const array ALLOWED_CONTEXT_KEYS = [
        'run_id',
        'feature',
        'provider',
        'model',
        'prompt_key',
        'prompt_version',
        'status',
        'failure_code',
        'latency_ms',
        'input_tokens',
        'output_tokens',
        'estimated_cost',
        'cost_currency',
        'provider_request_id',
        'attempt',
    ];

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->channel()->info($message, $this->filter($context));
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->channel()->warning($message, $this->filter($context));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, scalar|null>
     */
    private function filter(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            if (! in_array($key, self::ALLOWED_CONTEXT_KEYS, true)) {
                continue;
            }

            // Scalars only. An array or object could carry an entire
            // response body under an allowed key name.
            if ($value !== null && ! is_scalar($value)) {
                continue;
            }

            $safe[$key] = $value;
        }

        return $safe;
    }

    private function channel(): LoggerInterface
    {
        return Log::channel('ai');
    }
}
