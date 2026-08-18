<?php

declare(strict_types=1);

namespace App\Ai\Providers\OpenAi;

/**
 * The transport seam for OpenAI. Exists so OpenAiProvider — which holds
 * all the request/response SHAPE knowledge — can be tested and reasoned
 * about with no network, and so exactly one class ever authenticates
 * against OpenAI.
 *
 * Plain Laravel HTTP rather than the official SDK, for the same reason
 * RazorpayXHttpPayoutClient does: the surface used here is four JSON
 * endpoints, and an SDK would add a second, differently-shaped error
 * taxonomy to redact and classify.
 */
interface OpenAiClientInterface
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws OpenAiRequestException
     */
    public function post(string $path, array $payload, int $timeoutSeconds): OpenAiApiResponse;

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws OpenAiRequestException
     */
    public function get(string $path, array $query, int $timeoutSeconds): OpenAiApiResponse;
}
