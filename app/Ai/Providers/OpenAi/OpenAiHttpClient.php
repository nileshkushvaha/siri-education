<?php

declare(strict_types=1);

namespace App\Ai\Providers\OpenAi;

use App\Ai\Enums\AiFailureCode;
use App\Ai\Exceptions\AiConfigurationException;
use App\Ai\Services\AiCredentialStore;
use App\Ai\Support\RedactsProviderMessages;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The only class in this codebase that ever sends an OpenAI HTTP
 * request, and the only one that ever holds the decrypted key — for the
 * duration of building one PendingRequest.
 *
 * The API key is applied via withToken() and never placed in a URL,
 * a query string, a log line or an exception. Every error leaving this
 * class is an OpenAiRequestException carrying a redacted message and a
 * classified failure code, so a stack trace captured anywhere upstream
 * cannot contain the credential or the prompt.
 */
final class OpenAiHttpClient implements OpenAiClientInterface
{
    use RedactsProviderMessages;

    private const string BASE_URL = 'https://api.openai.com/v1';

    public function __construct(
        private readonly AiCredentialStore $credentials,
    ) {}

    public function post(string $path, array $payload, int $timeoutSeconds): OpenAiApiResponse
    {
        return $this->send(fn (PendingRequest $request): Response => $request->post($path, $payload), $timeoutSeconds);
    }

    public function get(string $path, array $query, int $timeoutSeconds): OpenAiApiResponse
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get($path, $query), $timeoutSeconds);
    }

    /** @param callable(PendingRequest): Response $send */
    private function send(callable $send, int $timeoutSeconds): OpenAiApiResponse
    {
        try {
            $response = $send($this->request($timeoutSeconds));
        } catch (AiConfigurationException $e) {
            throw new OpenAiRequestException($e->getMessage(), AiFailureCode::NotConfigured, previous: $e);
        } catch (ConnectionException $e) {
            // Laravel surfaces both connect failures and read timeouts
            // here; the message distinguishes them and is developer-
            // authored by cURL, not by the request body.
            $timedOut = str_contains(strtolower($e->getMessage()), 'timed out');

            throw new OpenAiRequestException(
                $timedOut ? 'OpenAI request timed out.' : 'OpenAI could not be reached.',
                $timedOut ? AiFailureCode::Timeout : AiFailureCode::ProviderUnavailable,
            );
        } catch (Throwable $e) {
            throw new OpenAiRequestException($this->redact($e->getMessage()), AiFailureCode::Unknown);
        }

        return $this->decode($response);
    }

    private function request(int $timeoutSeconds): PendingRequest
    {
        $request = Http::baseUrl(self::BASE_URL)
            ->withToken($this->credentials->openAiApiKey())
            ->acceptJson()
            ->asJson()
            ->timeout(max(1, $timeoutSeconds))
            ->connectTimeout(10);

        $organization = $this->credentials->openAiOrganization();

        return $organization !== null
            ? $request->withHeader('OpenAI-Organization', $organization)
            : $request;
    }

    private function decode(Response $response): OpenAiApiResponse
    {
        $requestId = $response->header('x-request-id') ?: null;
        $body = rescue(fn () => $response->json(), null, report: false);

        if ($response->failed()) {
            $error = is_array($body) ? ($body['error'] ?? []) : [];
            $code = is_array($error) ? ($error['code'] ?? $error['type'] ?? null) : null;
            $message = is_array($error) ? ($error['message'] ?? null) : null;

            throw new OpenAiRequestException(
                // Redacted because OpenAI's validation errors quote the
                // offending input back, and here that input is user
                // content.
                $this->redact(is_string($message) ? $message : 'OpenAI request failed.'),
                OpenAiFailureClassifier::fromStatus($response->status(), is_string($code) ? $code : null),
                httpStatus: $response->status(),
            );
        }

        if (! is_array($body)) {
            throw new OpenAiRequestException('OpenAI returned an unreadable response.', AiFailureCode::InvalidResponse, httpStatus: $response->status());
        }

        return new OpenAiApiResponse($body, is_string($requestId) ? $requestId : null);
    }
}
